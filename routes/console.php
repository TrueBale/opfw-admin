<?php

use Illuminate\Support\Facades\DB;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command"s IO methods.
|
*/

function runQuery(string $cluster, string $query)
{
	$dir = realpath(__DIR__ . "/../envs/" . $cluster);
	$env = $dir . "/.env";

	if (empty($env) || !file_exists($env)) {
		return [false, "Failed to read .env file"];
	}

	$contents = file_get_contents($env);

	$dotenv = Dotenv::createImmutable($dir, ".env");
	$envData = $dotenv->parse($contents);

	$dbName = "cluster_" . $cluster;

	Config::set("database.connections." . $dbName, [
		"driver" => $envData["DB_CONNECTION"],
		"host" => $envData["DB_HOST"],
		"port" => $envData["DB_PORT"],
		"database" => $envData["DB_DATABASE"],
		"username" => $envData["DB_USERNAME"],
		"password" => $envData["DB_PASSWORD"]
	]);

	try {
        DB::connection($dbName)->getPdo();
    } catch (\Exception $e) {
        return [false, "Failed to connect to database: " . $e->getMessage()];
    }

	$affected = 0;

	if (Str::startsWith($query, "SELECT")) {
		$affected = DB::connection($dbName)->select($query);

		$affected = count($affected);
	} else if (Str::startsWith($query, "UPDATE")) {
		$affected = DB::connection($dbName)->update($query);
	} else if (Str::startsWith($query, "INSERT")) {
		$affected = DB::connection($dbName)->insert($query);
	} else if (Str::startsWith($query, "DELETE")) {
		$affected = DB::connection($dbName)->delete($query);
	} else {
		return [false, "Unknown query type"];
	}

	return [true, "Affected " . $affected . " rows"];
}

// UPDATE `inventories` SET `item_name` = "weapon_addon_hk416" WHERE `item_name` = "weapon_addon_m4"
Artisan::command("run-query", function() {
	$query = trim($this->ask("SQL Query"));

	if (empty($query)) {
		$this->error("Query is empty");

		return;
	}

	$this->info("Iterating through all clusters...");

	$dir = __DIR__ . "/../envs";

	$clusters = array_diff(scandir($dir), [".", ".."]);

	chdir(__DIR__ . "/..");

	foreach ($clusters as $cluster) {
		$cluster = trim($cluster);

		$path = $dir . "/" . $cluster;

		if (empty($cluster) || !is_dir($path)) {
			continue;
		}

		$this->info("Running query on cluster `" . $cluster . "`...");

		$result = runQuery($cluster, $query);

		if (!$result[0]) {
			$this->error(" - " . $result[1]);
		} else {
			$this->comment(" - " . $result[1]);
		}
	}

	return;
})->describe("Runs a query on all clusters.");

Artisan::command("migrate-trunks", function() {
	$this->info(CLUSTER . " Loading inventories...");

	$inventories = DB::select("SELECT * FROM inventories WHERE inventory_name LIKE 'trunk-%' GROUP BY inventory_name");

	$this->info(CLUSTER . " Parsing " . sizeof($inventories) . " inventories...");

	$ids = [];

	$vehicleInventories = [];

	$npcs = 0;

	foreach ($inventories as $inventory) {
		$name = $inventory->inventory_name;

		$parts = explode("-", $name);

		if (sizeof($parts) !== 3) {
			continue;
		}

		if (preg_match('/[^0-9]/', $parts[2])) {
			$npcs++;

			continue;
		}

		$class = intval($parts[1]);
		$id = intval($parts[2]);

		$vehicleInventories[$id] = [
			"class" => $class,
			"name" => $name
		];

		$ids[] = $id;
	}

	$this->info(CLUSTER . " Skipped $npcs npc trunks...");

	$this->info(CLUSTER . " Loading vehicles...");

	$vehicles = DB::table("character_vehicles")->whereIn("vehicle_id", $ids)->get();

	$alphaModels = [
		-2137348917 => "phantom",
		-956048545 => "taxi",
		1162065741 => "rumpo",
		1353720154 => "flatbed"
	];

	$classes = json_decode(file_get_contents(__DIR__ . "/../helpers/vehicle_classes.json"), true);

	$this->info(CLUSTER . " Parsing " . sizeof($vehicles) . " vehicles...");

	$update = [];

	foreach($vehicles as $vehicle) {
		$id = intval($vehicle->vehicle_id);
		$model = $vehicle->model_name;

		if (!isset($vehicleInventories[$id])) {
			continue;
		}

		if (is_numeric($model)) {
			$model = intval($model);

			$model = $alphaModels[$model] ?? null;

			if (!$model) {
				continue;
			}
		}

		$expected = $classes[$model] ?? null;

		if (!$expected && $expected !== 0) {
			$expected = 22;
		}

		$wasName = $vehicleInventories[$id]["name"];
		$isName = "trunk-" . $expected . "-" . $id;

		if ($wasName === $isName) {
			continue;
		}

		$update[$wasName] = $isName;
	}

	$size = sizeof($update);

	if ($size > 0) {
		if (!$this->confirm(CLUSTER . " Found $size affected inventories, continue?", false)) {
			$this->info(CLUSTER . " Aborted!");

			return;
		}

		$this->info(CLUSTER . " Updating $size inventories...");

		$index = 1;

		foreach($update as $was => $is) {
			echo "$was ($index/$size)          \r";

			DB::update("UPDATE inventories SET inventory_name = ? WHERE inventory_name = ?", [$is, $was]);

			$index++;
		}

		$this->info(CLUSTER . " Finished updating $size inventories.                    ");
	} else {
		$this->info(CLUSTER . " No inventories to update.");
	}

	return;
})->describe("Update all trunks to have the correct vehicle class.");
