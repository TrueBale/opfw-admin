<?php

namespace App\Http\Controllers;

use App\Character;
use App\Helpers\OPFWHelper;
use App\Log;
use App\Player;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WeaponController extends Controller
{
	const MaximumDamage = 200;
	const MaximumDistance = 200;

    public function weaponDamage(Request $request, string $weapon): Response
    {
		$weapon = strtolower($weapon);
		$weaponHash = hexdec(hash("joaat", $weapon));

		$query = DB::table("weapon_damage_events")
			->selectRaw("COUNT(steam_identifier) as count, weapon_damage");

		$query2 = DB::table("weapon_damage_events")
			->selectRaw("AVG(weapon_damage) as damage, ROUND(distance) as dst");

		if ($request->has('ban')) {
			$query = $query->leftJoin("user_bans", function ($join) {
				$join->on("identifier", "=", "steam_identifier");
			})->whereNull("ban_hash");

			$query2 = $query2->leftJoin("user_bans", function ($join) {
				$join->on("identifier", "=", "steam_identifier");
			})->whereNull("ban_hash");
		}

		$data = $query->where("weapon_type", $weaponHash)
			->where("hit_players", "!=", "[]")
			->groupBy("weapon_damage")
			->orderByDesc("weapon_damage")
			->get()->toArray();

		$data2 = $query->where("weapon_type", $weaponHash)
			->where("hit_players", "!=", "[]")
			->whereNotNull("distance")
			->groupByRaw("ROUND(distance)")
			->orderByDesc("dst")
			->get()->toArray();

		$average = [
			'labels' => [],
            'data'   => [],
		];

		$distance = [
			'labels' => [],
            'data'   => [],
		];

		$data = array_values(array_filter($data, function($row) {
			return $row->count > 1;
		}));

		if (!empty($data)) {
			$maxDamage = min($data[0]->weapon_damage, self::MaximumDamage);

			$damages = [];

			for ($damage = $maxDamage; $damage >= 0; $damage--) {
				$damages[$damage] = 0;
			}

			foreach ($data as $row) {
				$damages[min($row->weapon_damage, self::MaximumDamage)] += $row->count;
			}

			foreach ($damages as $damage => $count) {
				$average['labels'][] = $damage === self::MaximumDamage ? self::MaximumDamage . "+" : $damage;
				$average['data'][] = $count;
			}

			$average['labels'] = array_reverse($average['labels']);
			$average['data'] = array_reverse($average['data']);
		}

		if (!empty($data2)) {
			$maxDistance = min($data2[0]->dst, self::MaximumDistance);

			$distances = [];

			for ($distance = $maxDistance; $distance >= 0; $distance--) {
				$distances[$distance] = 0;
			}

			foreach ($data2 as $row) {
				if ($row->dst <= self::MaximumDistance) {
					$distances[$row->dst] = $row->damage;
				}
			}

			foreach ($distances as $distance => $damage) {
				$distance['labels'][] = $distance;
				$distance['data'][] = $damage;
			}

			$distance['labels'] = array_reverse($distance['labels']);
			$distance['data'] = array_reverse($distance['data']);
		}

        return Inertia::render('Statistics/WeaponDamage', [
			'weapon' => $weapon,
			'weaponHash' => $weaponHash,
            'average' => $average,
			'distance' => $distance
        ]);
    }
}
