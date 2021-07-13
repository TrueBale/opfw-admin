<?php

namespace App\Http\Controllers;

use App\Character;
use App\Http\Resources\InventoryLogResource;
use App\Inventory;
use App\Log;
use App\Player;
use App\Property;
use App\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Display a inventory logs related to a character.
     *
     * @param Character $character
     * @param Request $request
     * @return Response
     */
    public function character(Character $character, Request $request): Response
    {
        $inventories = [
            'character-' . $character->character_id,
            'locker-police-' . $character->character_id,
            'locker-mechanic-' . $character->character_id,
            'locker-ems-' . $character->character_id,
        ];

        return $this->searchInventories($request, $inventories);
    }

    /**
     * Display a inventory logs related to a vehicle.
     *
     * @param Vehicle $vehicle
     * @param Request $request
     * @return Response
     */
    public function vehicle(Vehicle $vehicle, Request $request): Response
    {
        $type = $vehicle->vehicleType();

        $inventories = [
            'trunk-' . $type . '-' . $vehicle->plate,
            'trunk-' . $type . '-' . $vehicle->vehicle_id,
            'glovebox-' . $type . '-' . $vehicle->plate,
            'glovebox-' . $type . '-' . $vehicle->vehicle_id,
        ];

        return $this->searchInventories($request, $inventories);
    }

    /**
     * Display a inventory logs related to a property.
     *
     * @param Property $property
     * @param Request $request
     * @return Response
     */
    public function property(Property $property, Request $request): Response
    {
        $inventories = [
            'property-' . $property->property_id . '-%',
        ];

        return $this->searchInventories($request, $inventories, 'LIKE');
    }

    /**
     * @param Request $request
     * @param array $inventories
     * @param bool $likeSearch
     * @return Response
     */
    private function searchInventories(Request $request, array $inventories, bool $likeSearch = false): Response
    {
        $start = round(microtime(true) * 1000);

        $fromSql = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(`details`, ' to ', -1), ' from ', 1), ':', 1)";
        $toSql = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(`details`, ' to ', -1), ' from inventory ', -1), ':', 1)";

        if ($likeSearch) {
            $where = [];
            foreach ($inventories as $inventory) {
                $where[] = "(" . $fromSql . " LIKE '" . $inventory . "' OR $toSql LIKE '" . $inventory . "')";
            }
            $where = implode(' OR ', $where);
        } else {
            $where = $fromSql . " IN ('" . implode("', '", $inventories) . "')";
        }

        $page = Paginator::resolveCurrentPage('page');

        $sql = "SELECT `identifier`, `details`, `timestamp` FROM `user_logs` WHERE `action`='Item Moved' AND (" . $where . ") ORDER BY `timestamp` DESC LIMIT 15 OFFSET " . (($page - 1) * 15);

        $logs = InventoryLogResource::collection(DB::select($sql));

        $end = round(microtime(true) * 1000);

        return Inertia::render('Inventories/Index', [
            'logs'      => $logs,
            'playerMap' => Player::fetchSteamPlayerNameMap($logs->toArray($request), 'steamIdentifier'),
            'links'     => $this->getPageUrls($page),
            'time'      => $end - $start,
            'page'      => $page,
        ]);
    }

    /**
     * Display information related to an inventory.
     *
     * @param string $inventory
     * @param Request $request
     * @return Response
     */
    public function show(string $inventory, Request $request): Response
    {
        return Inertia::render('Inventories/Show', [
            'inventory' => Inventory::parseDescriptor($inventory)->get(),
        ]);
    }

}
