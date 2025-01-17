<?php

namespace App\Helpers;

use App\Helpers\LoggingHelper;
use App\OPFWResponse;
use App\PanelLog;
use App\Player;
use App\Server;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Throwable;

class OPFWHelper
{
    const RetryAttempts = 2;

    /**
     * Sends a staff pm to a player
     *
     * @param string $staffLicenseIdentifier
     * @param Player $player
     * @param string $message
     * @return OPFWResponse
     */
    public static function staffPM(string $staffLicenseIdentifier, Player $player, string $message): OPFWResponse
    {
        if (!$message) {
            return new OPFWResponse(false, 'Your message cannot be empty');
        }

        $status = Player::getOnlineStatus($player->license_identifier, false);
        if (!$status->isOnline()) {
            return new OPFWResponse(false, 'Player is offline.');
        }

        $response = self::executeRoute($status->serverIp, $status->serverIp . 'execute/staffPrivateMessage', [
            'licenseIdentifier' => $staffLicenseIdentifier,
            'targetSource'    => $status->serverId,
            'message'         => $message,
        ]);

        if ($response->status) {
            $response->message = 'Staff Message has been sent successfully.';

            PanelLog::logStaffPM($staffLicenseIdentifier, $player->license_identifier, $message);
        }

        return $response;
    }

    /**
     * Sends a staff chat message
     *
     * @param string $serverIp
     * @param string $staffLicenseIdentifier
     * @param string $message
     * @return OPFWResponse
     */
    public static function staffChat(string $serverIp, string $staffLicenseIdentifier, string $message): OPFWResponse
    {
        if (!$message) {
            return new OPFWResponse(false, 'Your message cannot be empty');
        }

        $response = self::executeRoute($serverIp, $serverIp . 'execute/staffChatMessage', [
            'licenseIdentifier' => $staffLicenseIdentifier,
            'message'         => $message,
        ]);

        if ($response->status) {
            $response->message = 'Staff Chat Message has been sent successfully.';
        }

        return $response;
    }

    /**
     * Sends a server message
     *
     * @param string $message
     * @return OPFWResponse
     */
    public static function serverAnnouncement(string $serverIp, string $message): OPFWResponse
    {
        if (!$message) {
            return new OPFWResponse(false, 'Your message cannot be empty.');
        }

        $response = self::executeRoute($serverIp, $serverIp . 'execute/announcementMessage', [
            'announcementMessage' => $message,
        ]);

        if ($response->status) {
            $response->message = 'Server Announcement has been posted successfully.';
        } else {
            $response->message = 'Failed to post server announcement.';
        }

        return $response;
    }

    /**
     * Kicks a player from the server
     *
     * @param string $staffLicenseIdentifier
     * @param string $staffPlayerName
     * @param Player $player
     * @param string $reason
     * @return OPFWResponse
     */
    public static function kickPlayer(string $staffLicenseIdentifier, string $staffPlayerName, Player $player, string $reason): OPFWResponse
    {
        $license = $player->license_identifier;

        $status = Player::getOnlineStatus($license, false);
        if (!$status->isOnline()) {
            return new OPFWResponse(false, 'Player is offline.');
        }

        if (env('HIDE_BAN_CREATOR')) {
            $staffPlayerName = "a staff member";
        }

        $response = self::executeRoute($status->serverIp, $status->serverIp . 'execute/kickPlayer', [
            'licenseIdentifier'         => $license,
            'reason'                  => 'You have been kicked by ' . $staffPlayerName . ' for reason `' . $reason . '`',
            'removeReconnectPriority' => false,
        ]);

        if ($response->status) {
            $response->message = 'Kicked player from the server.';

            PanelLog::logKick($staffLicenseIdentifier, $license, $reason);
        }

        return $response;
    }

    /**
     * Revives a player in the server
     *
     * @param string $staffLicenseIdentifier
     * @param string $licenseIdentifier
     * @return OPFWResponse
     */
    public static function revivePlayer(string $staffLicenseIdentifier, string $licenseIdentifier): OPFWResponse
    {
        $status = Player::getOnlineStatus($licenseIdentifier, false);
        if (!$status->isOnline()) {
            return new OPFWResponse(false, 'Player is offline.');
        }

        $response = self::executeRoute($status->serverIp, $status->serverIp . 'execute/revivePlayer', [
            'targetSource' => $status->serverId,
        ]);

        if ($response->status) {
            $response->message = 'Revived player.';

            PanelLog::logRevive($staffLicenseIdentifier, $licenseIdentifier);
        }

        return $response;
    }

    /**
     * Updates tattoo data for a player
     *
     * @param Player $player
     * @param string $character_id
     * @return OPFWResponse
     */
    public static function updateTattoos(Player $player, string $character_id): OPFWResponse
    {
        $license = $player->license_identifier;

        $status = Player::getOnlineStatus($license, false);
        if (!$status->isOnline()) {
            return new OPFWResponse(true, 'Player is offline, no refresh needed.');
        }

        $response = self::executeRoute($status->serverIp, $status->serverIp . 'execute/refreshTattoos', [
            'licenseIdentifier' => $license,
            'characterId'     => $character_id,
        ]);

        if ($response->status) {
            $response->message = 'Updated tattoo data for player.';
        }

        return $response;
    }

    /**
     * Updates character data for a player
     *
     * @param Player $player
     * @param string $character_id
     * @return OPFWResponse
     */
    public static function updateCharacter(Player $player, string $character_id): OPFWResponse
    {
        $license = $player->license_identifier;

        $status = Player::getOnlineStatus($license, false);
        if (!$status->isOnline()) {
            return OPFWResponse::didNotExecute();
        }

        $response = self::executeRoute($status->serverIp, $status->serverIp . 'execute/refreshCharacter', [
            'licenseIdentifier' => $license,
            'characterId'       => $character_id,
        ]);

        if ($response->status) {
            $response->message = 'Updated character data for player.';
        }

        return $response;
    }

    /**
     * Unloads someone's character
     *
     * @param string $staffLicenseIdentifier
     * @param Player $player
     * @param string $character_id
     * @param string $message
     * @return OPFWResponse
     */
    public static function unloadCharacter(string $staffLicenseIdentifier, Player $player, string $character_id, string $message): OPFWResponse
    {
        $license = $player->license_identifier;

        $status = Player::getOnlineStatus($license, false);
        if (!$status->isOnline()) {
            return new OPFWResponse(true, 'Player is offline, no unload needede.');
        }

        $response = self::executeRoute($status->serverIp, $status->serverIp . 'execute/unloadCharacter', [
            'licenseIdentifier' => $license,
            'characterId'     => $character_id,
            'message'         => $message,
        ]);

        if ($response->status) {
            $response->message = 'Unloaded players character.';

            PanelLog::logUnload($staffLicenseIdentifier, $license, $character_id, $message);
        }

        return $response;
    }

    /**
     * Updates someones queue position
     *
     * @param string $serverIp
     * @param string $licenseIdentifier
     * @param int $targetPosition
     * @return OPFWResponse
     */
    public static function updateQueuePosition(string $serverIp, string $licenseIdentifier, int $targetPosition): OPFWResponse
    {
        return self::executeRoute($serverIp, $serverIp . 'execute/setQueuePosition', [
            'licenseIdentifier' => $licenseIdentifier,
            'targetPosition'  => $targetPosition,
        ], 'PATCH');
    }

    /**
     * Gets the users.json (from the socket)
     *
     * @param string $serverIp
     * @return array|null
     */
    public static function getUsersJSON(string $serverIp): ?array
    {
        $server = Server::getServerName($serverIp);

        if (!$server) {
            return null;
        }

        $data = self::executeSocketRoute("data/$server/players");

        return $data ?? null;
    }

    /**
     * Gets the queue.json
     *
     * @param string $serverIp
     * @param bool $forceRefresh
     * @return array|null
     */
    public static function getQueueJSON(string $serverIp, bool $forceRefresh = false): ?array
    {
        $serverIp = Server::fixApiUrl($serverIp);
        $cache = 'queue_json_' . md5($serverIp);

        if (CacheHelper::exists($cache) && !$forceRefresh) {
            return CacheHelper::read($cache, []);
        } else {
            $data = self::executeRoute($serverIp, $serverIp . 'queue.json', [], 'GET', 3);

            if ($data->data) {
                CacheHelper::write($cache, $data->data, 3);
            } else if (!$data->status) {
                CacheHelper::write($cache, [], 3);
            }

            return $data->data;
        }
    }

    /**
     * Gets the crafting.txt
     *
     * @param string $serverIp
     * @return array|null
     */
    public static function getCraftingTxt(string $serverIp): ?string
    {
        $serverIp = Server::fixApiUrl($serverIp);
        $cache = 'crafting_' . md5($serverIp);

        if (CacheHelper::exists($cache)) {
            return CacheHelper::read($cache, "");
        } else {
            $data = self::executeRoute($serverIp, $serverIp . 'crafting.txt', [], 'GET', 3, true);

            if ($data->status) {
                CacheHelper::write($cache, $data->message, 12 * CacheHelper::HOUR);
            } else {
                CacheHelper::write($cache, "", 10);
            }

            return $data->message;
        }
    }

    /**
     * Gets the jobs.json
     *
     * @param string $serverIp
     * @return array|null
     */
    public static function getJobsJSON(string $serverIp): ?array
    {
        $serverIp = Server::fixApiUrl($serverIp);
        $cache = 'jobs_json_' . md5($serverIp);

        if (CacheHelper::exists($cache)) {
            return CacheHelper::read($cache, []);
        } else {
            $data = self::executeRoute($serverIp, $serverIp . 'jobs.json', [], 'GET', 3);

            if ($data->data) {
                CacheHelper::write($cache, $data->data, 12 * CacheHelper::HOUR);
            } else if (!$data->status) {
                CacheHelper::write($cache, [], 10);
            }

            return $data->data;
        }
    }

    /**
     * Gets the vehicles.json
     *
     * @param string $serverIp
     * @return array|null
     */
    public static function getVehiclesJSON(string $serverIp): ?array
    {
        $serverIp = Server::fixApiUrl($serverIp);
        $cache = 'vehicles_' . md5($serverIp);

        if (CacheHelper::exists($cache)) {
            return CacheHelper::read($cache, []);
        } else {
            $data = self::executeRoute($serverIp, $serverIp . 'vehicles.json', [], 'GET', 3);

            if ($data->data) {
                CacheHelper::write($cache, $data->data, 12 * CacheHelper::HOUR);
            } else if (!$data->status) {
                CacheHelper::write($cache, [], 10);
            }

            return $data->data;
        }
    }

    /**
     * Gets the exclusiveDealership.json
     *
     * @param string $serverIp
     * @return array|null
     */
    public static function getEDMJSON(string $serverIp): ?array
    {
        $serverIp = Server::fixApiUrl($serverIp);
        $cache = 'exclusive_dealership_' . md5($serverIp);

        if (CacheHelper::exists($cache)) {
            return CacheHelper::read($cache, []);
        } else {
            $data = self::executeRoute($serverIp, $serverIp . 'exclusiveDealership.json', [], 'GET', 3);

            if ($data->data) {
                CacheHelper::write($cache, $data->data, 1 * CacheHelper::HOUR);
            } else if (!$data->status) {
                CacheHelper::write($cache, [], 10);
            }

            return $data->data;
        }
    }

    /**
     * Gets the models.json
     *
     * @param string $serverIp
     * @return array|null
     */
    public static function getModelsJSON(string $serverIp): ?array
    {
        $serverIp = Server::fixApiUrl($serverIp);
        $cache = 'models_' . md5($serverIp);

        if (CacheHelper::exists($cache)) {
            return CacheHelper::read($cache, []);
        } else {
            $data = self::executeRoute($serverIp, $serverIp . 'models.json', [], 'GET', 3);

            if ($data->data) {
                CacheHelper::write($cache, $data->data, 12 * CacheHelper::HOUR);
            } else if (!$data->status) {
                CacheHelper::write($cache, [], 10);
            }

            return $data->data;
        }
    }

    /**
     * Gets the api.json
     *
     * @param string $serverIp
     * @return array|null
     */
    public static function getApiJSON(string $serverIp): ?array
    {
        $serverIp = Server::fixApiUrl($serverIp);

        $data = self::executeRoute($serverIp, $serverIp . 'api.json', [], 'GET', 1);

        if (!$data->status) {
            return null;
        }

        return $data->data;
    }

    /**
     * Creates a screenshot
     *
     * @param string $serverIp
     * @param int $id
     * @return OPFWResponse
     */
    public static function createScreenshot(string $serverIp, int $id, bool $drawHTML = true, int $lifespan = 3600): OPFWResponse
    {
        $serverIp = Server::fixApiUrl($serverIp);

        return self::executeRoute($serverIp, $serverIp . 'execute/createScreenshot', [
            'serverId' => $id,
            'lifespan' => $lifespan,
            'drawHTML' => $drawHTML
        ]);
    }

    /**
     * Creates a screen capture
     *
     * @param string $serverIp
     * @param int $id
     * @param int $duration
     * @return OPFWResponse
     */
    public static function createScreenCapture(string $serverIp, int $id, int $duration): OPFWResponse
    {
        $serverIp = Server::fixApiUrl($serverIp);

        return self::executeRoute($serverIp, $serverIp . 'execute/createScreenshot', [
            'serverId' => $id,
            'lifespan' => 60 * 60,
            'fps' => 30,
            'duration' => $duration * 1000
        ], 'POST', $duration + 15);
    }

    /**
     * Executes a socket route
     *
     * @param string $route
     */
    private static function executeSocketRoute(string $route)
    {
        $token = sessionKey();
        $license = license();

        if (!$token || !$license) {
            return false;
        }

        $url = "http://localhost:9999/" . $route;

        $client = new Client(
            [
                'verify' => false,
                'timeout' => 2
            ]
        );

        $statusCode = 0;

        LoggingHelper::log('Do GET to "' . $url . '"');

        try {
            $res = $client->request("GET", $url, [
                'query' => [
                    'token' => $token,
                ],
            ]);

            $response = (string) $res->getBody();

            $statusCode = $res->getStatusCode() . " " . $res->getReasonPhrase();
        } catch (Throwable $t) {
            $response = $t->getMessage();
        }

        $log = $response;

        if (empty($log)) {
            $log = '-empty-';
        }

        if (strlen($log) > 300) {
            $log = substr($log, 0, 150) . '...';
        }

        LoggingHelper::log($statusCode . ': ' . $log);

        $json = json_decode($response, true);

        if (!$json || !$json['status']) {
            return false;
        }

        return $json['data'];
    }

    /**
     * Executes an op-fw route
     *
     * @param string $route
     * @param array $data
     * @param string $requestType
     * @param int $timeout
     * @return OPFWResponse
     */
    private static function executeRoute(string $serverIp, string $route, array $data, string $requestType = 'POST', int $timeout = 10, bool $isText = false): OPFWResponse
    {
        $token = env('OP_FW_TOKEN');

        if (!$token) {
            return new OPFWResponse(false, 'Invalid OP-FW configuration.');
        }

        /*
        if (!CacheHelper::getServerStatus($serverIp)) {
            return new OPFWResponse(false, 'Server is offline (cached).');
        }
        */

        if (Str::contains($route, 'localhost')) {
            $route = str_replace('https://', 'http://', $route);
        }

        $result = null;

        $client = new Client(
            [
                'verify' => false,
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        for ($x = 0; $x < self::RetryAttempts; $x++) {
            $statusCode = 0;

            LoggingHelper::log('Do ' . $requestType . ' to "' . $route . '"');
            LoggingHelper::log('Data: ' . json_encode($data));

            try {
                $res = $client->request($requestType, $route, [
                    'query' => $data,
                ]);

                $response = (string) $res->getBody();

                $statusCode = $res->getStatusCode() . " " . $res->getReasonPhrase();
            } catch (Throwable $t) {
                $response = $t->getMessage();
            }

            $log = $response;

            if (empty($log)) {
                $log = '-empty-';
            }

            if (strlen($log) > 300) {
                $log = substr($log, 0, 150) . '...';
            }

            LoggingHelper::log($statusCode . ': ' . $log);

            if ($isText) {
                return new OPFWResponse(true, $response);
            }

            $result = self::parseResponse($response);

            if (!$result->status) {
                if ($x + 1 < self::RetryAttempts) {
                    sleep(2);
                }
            } else {
                return $result;
            }
        }

        return $result;
    }

    /**
     * @param string $response
     * @return OPFWResponse
     */
    public static function parseResponse(string $response): OPFWResponse
    {
        // Sometimes the server sends stupid json responses with invalid characters
        $response = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $response);

        $json = json_decode($response, true);

        $code = 0;

        if ($json && isset($json['statusCode'])) {
            $code = intval($json['statusCode']);
            $category = floor(intval($json['statusCode']) / 100);

            switch (intval($json['statusCode'])) {
                case 401:
                    return new OPFWResponse(false, 'Invalid OP-FW configuration. Wrong token?');
                case 400:
                case 403:
                case 404:
                    return new OPFWResponse(false, !empty($json['message']) ? $json['message'] : 'Unknown error');
            }

            switch ($category) {
                case 2: // All 200 status codes
                    return new OPFWResponse(true, !empty($json['message']) ? 'Success: ' . $json['message'] : 'Successfully executed route', $json['data'] ?? null);
            }

            return new OPFWResponse(false, 'Failed to execute route: "Unknown server response ' . $code . '"');
        }

        $error = json_last_error();

        if ($error !== JSON_ERROR_NONE) {
            return new OPFWResponse(false, 'Failed to execute route: "Invalid response json: ' . self::jsonErrorToString($error) . '"');
        }

        return new OPFWResponse(false, 'Failed to execute route: "Invalid server response ' . $code . '"');
    }

    private static function jsonErrorToString(int $error): string
    {
        switch ($error) {
            case JSON_ERROR_NONE:
                return 'No errors';
                break;
            case JSON_ERROR_DEPTH:
                return 'Maximum stack depth exceeded';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                return 'Underflow or the modes mismatch';
                break;
            case JSON_ERROR_CTRL_CHAR:
                return 'Unexpected control character found';
                break;
            case JSON_ERROR_SYNTAX:
                return 'Syntax error, malformed JSON';
                break;
            case JSON_ERROR_UTF8:
                return 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
            default:
                return 'Unknown error';
                break;
        }
    }
}
