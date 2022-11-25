<?php

namespace App\Http\Controllers;

use App\Character;
use App\Helpers\OPFWHelper;
use App\Log;
use App\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    public function logs(Request $request, string $action): Response
    {
        $action = trim($action);

        if (!$action) {
            return self::respond("Empty action!");
        }

        $details = trim($request->input('details'));

        $all = Log::query()
            ->selectRaw('`player_name`, COUNT(`identifier`) as `amount`')
            ->where('action', '=', $action);

        if ($details) {
            $all->where('details', 'LIKE', '%' . $details . '%');
        }

        $all = $all->groupBy('identifier')
            ->leftJoin('users', 'identifier', '=', 'steam_identifier')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();

        $last24hours = Log::query()
            ->selectRaw('`player_name`, COUNT(`identifier`) as `amount`')
            ->where('action', '=', $action);

        if ($details) {
            $last24hours->where('details', 'LIKE', '%' . $details . '%');
        }

        $last24hours = $last24hours->where(DB::raw('UNIX_TIMESTAMP(`timestamp`)'), '>', time() - 24 * 60 * 60)
            ->groupBy('identifier')
            ->leftJoin('users', 'identifier', '=', 'steam_identifier')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();

        $text = self::renderStatistics($action, "24 hours", $last24hours, $details);
        $text .= "\n\n";
        $text .= self::renderStatistics($action, "30 days", $all, $details);

        return self::respond($text);
    }

    private static function renderStatistics(string $type, string $timespan, $rows, $details): string
    {
        $lines = [
            "Top 10 Logs of type `" . $type . "` in the past " . $timespan . ":",
            $details ? "- Details like: `" . $details . "`\n" : "",
        ];

        foreach ($rows as $message) {
            $lines[] = $message->player_name . ': ' . $message->amount;
        }

        return implode("\n", $lines);
    }

    public function smartWatchLeaderboard(): Response
    {
        $all = DB::table('inventories')
            ->select('item_metadata')
            ->where('item_name', '=', 'smart_watch')
            ->get()
            ->toArray();

        $leaderboard = [];

        foreach ($all as $item) {
            $metadata = json_decode($item->item_metadata, true);

            if ($metadata && isset($metadata['firstName']) && isset($metadata['lastName'])) {
                $name = $metadata['firstName'] . ' ' . $metadata['lastName'];

                if (!isset($leaderboard[$name])) {
                    $leaderboard[$name] = [
                        'steps' => 0,
                        'deaths' => 0
                    ];
                }

                if (isset($metadata['stepsWalked'])) {
                    $steps = floor(floatval($metadata['stepsWalked']));

                    if ($leaderboard[$name]['steps'] < $steps) {
                        $leaderboard[$name]['steps'] = $steps;
                    }
                }

                if (isset($metadata['deaths'])) {
                    $deaths = intval($metadata['deaths']);

                    if ($leaderboard[$name]['deaths'] < $deaths) {
                        $leaderboard[$name]['deaths'] = $deaths;
                    }
                }
            }
        }

        $list = [];

        foreach ($leaderboard as $name => $data) {
            $list[] = [
                'name' => $name,
                'steps' => $data['steps'],
                'deaths' => $data['deaths']
            ];
        }

        usort($list, function ($a, $b) {
            return $b['steps'] - $a['steps'];
        });

        $index = 0;

        $stepsList = array_map(function ($entry) use (&$index) {
            $index++;

            return $index . ".\t" . number_format($entry['steps']) . "\t" . $entry['name'];
        }, array_splice($list, 0, 15));

        usort($list, function ($a, $b) {
            return $b['deaths'] - $a['deaths'];
        });

        $index = 0;

        $deathsList = array_map(function ($entry) use (&$index) {
            $index++;

            return $index . ".\t" . number_format($entry['deaths']) . "\t" . $entry['name'];
        }, array_splice($list, 0, 15));

        $text = "Top 15 steps traveled\n\nSpot\tSteps\tFull-Name\n" . implode("\n", $stepsList);
        $text .= "\n\n- - -\n\n";
        $text .= "Top 15 deaths\n\nSpot\tDeaths\tFull-Name\n" . implode("\n", $deathsList);

        return self::respond($text);
    }

    public function banLeaderboard(): Response
    {
        $staff = Player::query()->select(["steam_identifier", "player_name"])->where("is_staff", "=", "1")->orWhere("is_senior_staff", "=", "1")->orWhere("is_super_admin", "=", "1")->get();

        $max = 0;
        $staffMap = [];

        foreach ($staff as $player) {
            $staffMap[$player->steam_identifier] = $player->player_name;

            if (strlen($player->player_name) > $max) {
                $max = strlen($player->player_name);
            }
        }

        // What a chonker
        $query = "SELECT * FROM (SELECT identifier, creator_identifier, reason, (SELECT SUM(playtime) FROM characters WHERE steam_identifier = identifier) as playtime FROM user_bans WHERE identifier LIKE 'steam:%' AND creator_identifier LIKE 'steam:%' AND creator_identifier IN ('" . implode("', '", array_keys($staffMap)) . "') AND timestamp >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))) bans WHERE playtime IS NOT NULL AND playtime > 0 ORDER BY playtime LIMIT 10";

        $bans = DB::select($query);

        $fmt = function ($s) {
            if ($s >= 60) {
                $m = floor($s / 60);
                $s -= $m * 60;

                return $m . "m " . $s . "s";
            }

            return $s . "s";
        };

        $leaderboard = [];
        for ($x = 0; $x < sizeof($bans) && $x < 10; $x++) {
            $ban = $bans[$x];

            $leaderboard[] = str_pad(($x + 1) . "", 2, "0", STR_PAD_LEFT) . ". " . str_pad($staffMap[$ban->creator_identifier], $max, " ") . "  " . $ban->identifier . "\t" . $fmt(intval($ban->playtime)) . "\t" . ($ban->reason ?? "No reason");
        }

        $bans = DB::select("SELECT COUNT(identifier) c, creator_identifier FROM user_bans WHERE identifier LIKE \"steam:%\" AND timestamp >= " . (strtotime("-3 months")) . " AND creator_identifier IN ('" . implode("', '", array_keys($staffMap)) . "') GROUP BY creator_identifier ORDER BY c DESC");

        $leaderboard2 = [];
        for ($x = 0; $x < sizeof($bans) && $x < 10; $x++) {
            $ban = $bans[$x];

            $leaderboard2[] = str_pad(($x + 1) . "", 2, "0", STR_PAD_LEFT) . ". " . str_pad($staffMap[$ban->creator_identifier], $max, " ") . "  " . $ban->c . " bans";
        }

        $text = "Top 10 quickest bans (Last 3 months)\n\n" . implode("\n", $leaderboard) . "\n\n- - -\n\nTop 10 most bans (Last 3 months)\n\n" . implode("\n", $leaderboard2);

        if (isset($_GET["all"])) {
            $bans = DB::select("SELECT COUNT(identifier) c, creator_identifier FROM user_bans WHERE identifier LIKE \"steam:%\" AND creator_identifier IN ('" . implode("', '", array_keys($staffMap)) . "') GROUP BY creator_identifier ORDER BY c DESC");

            $leaderboard3 = [];
            foreach ($bans as $x => $ban) {
                $leaderboard3[] = str_pad(($x + 1) . "", 2, "0", STR_PAD_LEFT) . ". " . str_pad($staffMap[$ban->creator_identifier], $max, " ") . "  " . $ban->c . " bans";
            }

            $text .= "\n\n- - -\n\nTop 10 most bans (All time)\n\n" . implode("\n", $leaderboard3);
        }

        return self::respond($text);
    }

    private function formatSecondsMinimal($seconds)
    {
        $seconds = floor($seconds);

        $hours = floor($seconds / 3600);
        $seconds -= $hours * 3600;

        $minutes = floor($seconds / 60);
        $seconds -= $minutes * 60;

        $time = "";

        if ($hours > 0) {
            $time .= $hours . "h ";
        }

        if ($minutes > 0) {
            $time .= $minutes . "m ";
        }

        if ($seconds > 0) {
            $time .= $seconds . "s";
        }

        return "~" . $time;
    }

    public function systemBans(): Response
    {
        $all = DB::select("SELECT COUNT(*) AS count, SUBSTRING_INDEX(reason, '-', 2) AS reason, SUM(playtime) / COUNT(*) as playtime FROM user_bans LEFT JOIN users ON steam_identifier = identifier WHERE creator_name IS NULL AND identifier LIKE 'steam:%' AND (reason LIKE 'MODDING-%' OR reason LIKE 'MEDIOCRE-%' OR reason LIKE 'INJECTION-%' OR reason LIKE 'NO_PERMISSIONS-%' OR reason LIKE 'ILLEGAL_VALUES-%' OR reason LIKE 'TIMEOUT_BYPASS-%') GROUP BY SUBSTRING_INDEX(reason, '-', 2) LIMIT 20");
        $month = DB::select("SELECT COUNT(*) AS count, SUBSTRING_INDEX(reason, '-', 2) AS reason, SUM(playtime) / COUNT(*) as playtime FROM user_bans LEFT JOIN users ON steam_identifier = identifier WHERE creator_name IS NULL AND identifier LIKE 'steam:%' AND timestamp >= " . (strtotime("-1 month")) . " AND (reason LIKE 'MODDING-%' OR reason LIKE 'MEDIOCRE-%' OR reason LIKE 'INJECTION-%' OR reason LIKE 'NO_PERMISSIONS-%' OR reason LIKE 'ILLEGAL_VALUES-%' OR reason LIKE 'TIMEOUT_BYPASS-%') GROUP BY SUBSTRING_INDEX(reason, '-', 2) LIMIT 20");

        usort($all, function ($a, $b) {
            return $b->count - $a->count;
        });

        usort($month, function ($a, $b) {
            return $b->count - $a->count;
        });

        $totalPlaytime = array_reduce($all, function ($carry, $item) {
            return $carry + $item->playtime;
        }, 0) / sizeof($all);

        $monthPlaytime = array_reduce($month, function ($carry, $item) {
            return $carry + $item->playtime;
        }, 0) / sizeof($month);

        $allCount = array_reduce($all, function ($carry, $item) {
            return $carry + $item->count;
        }, 0);

        $monthCount = array_reduce($month, function ($carry, $item) {
            return $carry + $item->count;
        }, 0);

        $leaderboard = [];
        foreach ($month as $x => $ban) {
            $count = str_pad(number_format($ban->count), 6);

            $percentage = str_pad(number_format(($ban->count / $monthCount) * 100, 1) . "%", 6);
            $playtime = str_pad($this->formatSecondsMinimal($ban->playtime), 13);

            $leaderboard[] = str_pad(($x + 1) . "", 2, "0", STR_PAD_LEFT) . ". " . $percentage . " " . $count . " " . $playtime . " " . $ban->reason;
        }

        $leaderboard2 = [];
        foreach ($all as $x => $ban) {
            $count = str_pad(number_format($ban->count), 6);

            $percentage = str_pad(number_format(($ban->count / $allCount) * 100, 1) . "%", 6);
            $playtime = str_pad($this->formatSecondsMinimal($ban->playtime), 13);

            $leaderboard2[] = str_pad(($x + 1) . "", 2, "0", STR_PAD_LEFT) . ". " . $percentage . " " . $count . " " . $playtime . " " . $ban->reason;
        }

        $text = "Last 30 days (" . $this->formatSecondsMinimal($monthPlaytime) . ")\n\n" . implode("\n", $leaderboard) . "\n\n- - -\n\nAll time (" . $this->formatSecondsMinimal($totalPlaytime) . ")\n\n" . implode("\n", $leaderboard2);

        return self::respond($text);
    }

    public function moddingBans(Request $request): Response
    {
        $user = $request->user();
        if (!$user->player->is_super_admin) {
            return self::respond('Only super admins can export bans.');
        }

        $keywords = [
            "cheat",
            "modder",
            "modding",
            "script",
            "hacker",
            "hacking",
            "inject"
        ];

        foreach ($keywords as &$word) {
            $word = "reason like \"%" . $word . "%\"";
        }

        $query = "select identifier, reason from user_bans where identifier like \"steam:%\" and (" . implode(" or ", $keywords);

        if (CLUSTER === "c3") {
            $query .= " or (reason like \"%1.5%\" and timestamp > 1614553200)";
        }

        $query .= ") GROUP BY identifier ORDER BY timestamp";

        $bans = DB::select($query);

        $fd = fopen('php://temp/maxmemory:1048576', 'w');

        fputcsv($fd, ["steam_identifier", "reason"]);

        foreach ($bans as $ban) {
            fputcsv($fd, [$ban->identifier, $ban->reason]);
        }

        rewind($fd);
        $csv = stream_get_contents($fd);
        fclose($fd);

        return (new Response($csv, 200))
            ->header('Content-Type', 'application/octet-stream')
            ->header("Content-Transfer-Encoding", "Binary")
            ->header("Content-disposition", "attachment; filename=\"modders.csv\"");
    }

    public function staffPlaytime(Request $request): Response
    {
        $user = $request->user();
        if (!$user->player->is_super_admin) {
            return self::respond('Only super admins can do this.');
        }

        $staff = Player::query()->select(["steam_identifier", "player_name", "playtime"])->orWhere("is_staff", "=", "1")->orWhere("is_senior_staff", "=", "1")->orWhere("is_super_admin", "=", "1")->get();

        $entries = [];

        foreach ($staff as $player) {
            $entries[] = [
                'steam' => $player->steam_identifer,
                'name' => $player->player_name,
                'playtime' => intval($player->playtime)
            ];
        }

        usort($entries, function ($a, $b) {
            return $b['playtime'] - $a['playtime'];
        });

        $text = "Staff playtime\n\n";

        foreach ($entries as $entry) {
            $seconds = $entry['playtime'];

            $minutes = floor($seconds / 60);
            $seconds -= $minutes * 60;

            $hours = floor($minutes / 60);
            $minutes -= $hours * 60;

            $time = str_pad($hours . "h " . $minutes . "m " . $seconds . "s", 12);

            $text .= $time . " - " . $entry['name'] . " (" . $entry['steam'] . ")\n";
        }

        return self::respond($text);
    }

    public function jobApi(Request $request, string $api_key, string $jobName, string $departmentName, string $positionName, string $characterIds): Response
    {
        if (env('DEV_API_KEY', '') !== $api_key || empty($api_key)) {
            return (new Response('Unauthorized', 403))->header('Content-Type', 'text/plain');
        }

        $characterIds = explode(',', $characterIds);

        if (empty($characterIds)) {
            return (new Response('No character_ids provided', 400))->header('Content-Type', 'text/plain');
        }

        $characters = Character::query()
            ->select(["steam_identifier", "character_id", "job_name", "department_name", "position_name", "first_name", "last_name"])
            ->whereIn('character_id', $characterIds)
            ->orWhere(function ($query) use ($jobName, $departmentName, $positionName) {
                return $query->where('job_name', $jobName)
                    ->where('department_name', $departmentName)
                    ->where('position_name', $positionName);
            })
            ->get()->toArray();

        return (new Response(json_encode($characters), 200))->header('Content-Type', 'application/json');
    }

    /**
     * Responds with plain text
     *
     * @param string $data
     * @return Response
     */
    private static function respond(string $data): Response
    {
        return (new Response($data, 200))->header('Content-Type', 'text/plain');
    }
}
