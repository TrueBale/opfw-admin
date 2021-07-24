<?php

namespace App;

use App\Helpers\OPFWHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * @package App
 */
class Server extends Model
{

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webpanel_servers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'url',
    ];

    /**
     * A steamidentifier->serverId map
     *
     * @var array
     */
    private static array $onlineMap = [];

    /**
     * Gets the API data.
     *
     * @return array
     */
    public function fetchApi(): array
    {
        $data = Http::get(self::fixApiUrl($this->url) . 'api.json')->body() ?? null;

        $response = OPFWHelper::parseResponse($data);

        return $response->status && $response->data ? $response->data : [];
    }

    /**
     * Gets the api url
     *
     * @param string $serverIp
     * @return string
     */
    public static function fixApiUrl(string $serverIp): string
    {
        $serverIp = Str::finish(trim($serverIp), '/');

        if (!Str::endsWith($serverIp, '/op-framework/')) {
            $serverIp .= 'op-framework/';
        }

        if (!Str::startsWith($serverIp, 'https://')) {
            $serverIp = 'https://' . $serverIp;
        }

        return $serverIp;
    }

    public static function getServerName(string $serverIp): string
    {
        $serverIp = self::fixApiUrl($serverIp);
        return explode('.', str_replace('https://', '', $serverIp))[0];
    }

    /**
     * Returns an associative array (steamIdentifier -> serverId)
     *
     * @param string $serverIp
     * @param bool $useCache
     * @return array
     */
    public static function fetchSteamIdentifiers(string $serverIp, bool $useCache): array
    {
        if (!$serverIp) {
            return [];
        }
        $cacheKey = 'identifiers_' . md5($serverIp);

        if ($useCache) {
            if (Cache::store('file')->has($cacheKey)) {
                return Cache::store('file')->get($cacheKey);
            }
        }

        if (!isset(self::$onlineMap[$cacheKey]) || empty(self::$onlineMap[$cacheKey])) {
            $serverIp = self::fixApiUrl($serverIp);

            $json = null;
            try {
                $json = Http::timeout(3)->get($serverIp . 'connections.json')->body() ?? null;
            } catch (Throwable $t) {
                return [];
            }

            $response = OPFWHelper::parseResponse($json);

            if ($response->status && $response->data) {
                $json = $response->data;
                $assoc = [];

                foreach ($json['joining']['players'] as $player) {
                    $assoc[$player['steamIdentifier']] = $player['source'];
                }

                foreach ($json['joined']['players'] as $player) {
                    $assoc[$player['steamIdentifier']] = $player['source'];
                }

                self::$onlineMap[$cacheKey] = $assoc;
            } else {
                return [];
            }
        }

        Cache::store('file')->set($cacheKey, self::$onlineMap[$cacheKey], 5 * 60);

        return self::$onlineMap[$cacheKey];
    }

    /**
     * Collects all the /api.json data from all servers
     *
     * @return array
     */
    public static function collectAllApiData(): array
    {
        $serverIps = explode(',', env('OP_FW_SERVERS', ''));

        if (!$serverIps) {
            return [];
        }

        if (Cache::store('file')->has('server_all_api')) {
            return Cache::store('file')->get('server_all_api');
        }

        $result = [];
        foreach ($serverIps as $serverIp) {
            if ($serverIp) {
                $serverIp = self::fixApiUrl($serverIp);

                try {
                    $json = Http::timeout(3)->get($serverIp . 'api.json')->body() ?? [];

                    $response = OPFWHelper::parseResponse($json);

                    if ($response->status && $response->data) {
                        $json = $response->data;

                        $result[] = [
                            'joined' => isset($json['joinedAmount']) ? intval($json['joinedAmount']) : 0,
                            'total'  => isset($json['maxClients']) ? intval($json['maxClients']) : 0,
                            'queue'  => isset($json['queueAmount']) ? intval($json['queueAmount']) : 0,
                        ];
                    }
                } catch (Throwable $t) {
                }
            }
        }

        Cache::store('file')->set('server_all_api', $result, 5 * 60);

        return $result;
    }

}