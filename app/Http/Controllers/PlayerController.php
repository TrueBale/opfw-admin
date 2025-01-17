<?php

namespace App\Http\Controllers;

use App\Ban;
use App\BlacklistedIdentifier;
use App\Character;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\PlayerRouteController;
use App\Http\Resources\CharacterResource;
use App\Http\Resources\PanelLogResource;
use App\Http\Resources\PlayerIndexResource;
use App\Http\Resources\PlayerResource;
use App\Player;
use App\Screenshot;
use App\Warning;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        $start = round(microtime(true) * 1000);

        $query = Player::query();

        // Filtering by name.
        if ($name = $request->input('name')) {
            if (Str::startsWith($name, '=')) {
                $name = Str::substr($name, 1);
                $query->where('player_name', $name);
            } else {
                $query->where(function ($q) use ($name) {
                    $q->where('player_name', 'like', "%{$name}%");
                    $q->orWhere('player_aliases', 'like', "%{$name}%");
                });
            }
        }

        // Filtering by identifier & type.
        $identifier = $request->input('identifier');
        $identifier = $identifier ? preg_replace('/[^a-z0-9:]/i', '', $identifier) : null;

        $type = $request->input('identifier_type');
        $type = $type && !Str::contains($identifier, ':') ? preg_replace('/[^a-z0-9]/i', '', $type) : null;

        if ($identifier) {
            $id = '"' . $identifier . '"';

            if ($type) {
                $id = '"' . $type . ':' . $identifier . '"';
            }

            $query->where(DB::raw("JSON_CONTAINS(identifiers, '$id')"), '=', '1');
        }

        // Filtering by license_identifier.
        if ($license = $request->input('license')) {
            if (!Str::startsWith($license, 'license:')) {
                $license = 'license:' . $license;
            }

            if (strlen($license) !== 48) {
                $query->where('license_identifier', 'LIKE', '%' . $license);
            } else {
                $query->where('license_identifier', $license);
            }
        }

        // Filtering by serer-id.
        if ($server = $request->input('server')) {
            $online = array_keys(array_filter(Player::getAllOnlinePlayers(true) ?? [], function ($player) use ($server) {
                return $player['id'] === intval($server);
            }));

            $query->whereIn('license_identifier', $online);
        }

        // Filtering by enabled command
        $enablable = $request->input('enablable');
        if (in_array($enablable, PlayerRouteController::EnablableCommands)) {
            $query->where(DB::raw('JSON_CONTAINS(enabled_commands, \'"' . $enablable . '"\')'), '=', '1');
        }

        $query->orderBy("player_name");

        $query->select([
            'license_identifier', 'player_name', 'playtime', 'identifiers', 'player_aliases',
        ]);
        $query->selectSub('SELECT COUNT(`id`) FROM `warnings` WHERE `player_id` = `user_id` AND `warning_type` IN (\'' . Warning::TypeWarning . '\', \'' . Warning::TypeStrike . '\')', 'warning_count');

        $page = Paginator::resolveCurrentPage('page');
        $query->limit(15)->offset(($page - 1) * 15);

        $players = $query->get();

        if ($players->count() === 1) {
            $player = $players->first();

            return redirect('/players/' . $player->license_identifier);
        }

        $identifiers = array_values(array_map(function ($player) {
            return $player['license_identifier'];
        }, $players->toArray()));

        $end = round(microtime(true) * 1000);

        return Inertia::render('Players/Index', [
            'players'   => PlayerIndexResource::collection($players),
            'banMap'    => Ban::getAllBans(false, $identifiers, true),
            'filters'   => [
                'name'            => $request->input('name'),
                'license'         => $request->input('license'),
                'server'          => $request->input('server'),
                'identifier'      => $request->input('identifier'),
                'identifier_type' => $request->input('identifier_type') ?? '',
                'enablable'       => $request->input('enablable') ?? '',
            ],
            'links'     => $this->getPageUrls($page),
            'time'      => $end - $start,
            'page'      => $page,
            'enablable' => PlayerRouteController::EnablableCommands,
        ]);
    }

    /**
     * Display a listing of all online new players.
     *
     * @return Response
     */
    public function newPlayers(): Response
    {
        $query = Player::query();

        $playerList = Player::getAllOnlinePlayers(false) ?? [];
        $players    = array_keys($playerList);

        $query->whereIn('license_identifier', $players);
        $query->where('playtime', '<=', 60 * 60 * 12);

        $query->orderBy('playtime');

        $players = $query->get();

        $characterIds = [];

        foreach ($players as $player) {
            $status = Player::getOnlineStatus($player->license_identifier, true);

            if ($status->character) {
                $characterId = $status->character;

                $characterIds[] = $characterId;
            }
        }

        $characters = !empty($characterIds) ? Character::query()->whereIn('character_id', $characterIds)->get() : [];

        $playerList = [];

        foreach ($players as $player) {
            $character = null;

            foreach ($characters as $char) {
                if ($char->license_identifier === $player->license_identifier) {
                    $character = $char;

                    break;
                }
            }

            if (!$character) {
                continue;
            }

            $status = Player::getOnlineStatus($player->license_identifier, true);

            $playerList[] = [
                'serverId'          => $status && $status->serverId ? $status->serverId : null,
                'character'         => [
                    'name'                    => $character->first_name . ' ' . $character->last_name,
                    'backstory'               => $character->backstory,
                    'character_creation_time' => $character->character_creation_time,
                    'gender'                  => $character->gender == 1 ? 'female' : 'male',
                    'date_of_birth'           => $character->date_of_birth,
                    'ped_model_hash'          => $character->ped_model_hash,
                    'creationTime'            => intval($character->character_creation_time),
                    'danny'                   => GeneralHelper::dannyPercentageCreationTime(intval($character->character_creation_time)),
                    'data'                    => $status->characterMetadata ?? [],
                ],
                'playerName'        => $player->getSafePlayerName(),
                'playTime'          => $player->playtime,
                'licenseIdentifier' => $player->license_identifier,
            ];
        }

        return Inertia::render('Players/NewPlayers', [
            'players' => $playerList,
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param Player $player
     * @return Response|void
     */
    public function show(Request $request, Player $player)
    {
        $whitelisted = DB::table('user_whitelist')
            ->select(['license_identifier'])
            ->where('license_identifier', '=', $player->license_identifier)
            ->first();

        $identifiers = $player->getIdentifiers();

        $blacklisted = !empty($identifiers) ? BlacklistedIdentifier::query()
            ->select(['identifier'])
            ->whereIn('identifier', $identifiers)
            ->first() : false;

        $isSenior = $this->isSeniorStaff($request);

        return Inertia::render('Players/Show', [
            'player'            => new PlayerResource($player),
            'characters'        => CharacterResource::collection($player->characters),
            'warnings'          => $player->fasterWarnings($isSenior),
            'kickReason'        => trim($request->query('kick')) ?? '',
            'whitelisted'       => !!$whitelisted,
            'blacklisted'       => !!$blacklisted,
            'tags'              => Player::resolveTags(),
            'allowRoleEdit'     => env('ALLOW_ROLE_EDITING', false) && $this->isSuperAdmin($request),
            'enablableCommands' => PlayerRouteController::EnablableCommands,
        ]);
    }

    /**
     * Extra data loaded via ajax.
     *
     * @param Player $player
     * @return Response|void
     */
    public function extraData(Player $player)
    {
        $data = [
            'panelLogs'   => PanelLogResource::collection($player->panelLogs()->orderByDesc('timestamp')->limit(10)->get()),
            'screenshots' => Screenshot::getAllScreenshotsForPlayer($player->license_identifier, 10),
        ];

        return $this->json(true, $data);
    }

}
