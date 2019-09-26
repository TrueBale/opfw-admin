<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SteamID;

/**
 * @package App
 *
 * @property string account_id
 * @property string name
 * @property string avatar
 */
class User extends Authenticatable
{
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'account_id', 'name', 'avatar',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'remember_token',
    ];

    /**
     * Gets the game-server identifier.
     *
     * @return string
     */
    protected function getIdentifierAttribute() : string
    {
        return 'steam:' . dechex($this->account_id);
    }

    /**
     * Gets the player on the game-server associated with this user.
     *
     * @return HasOne
     */
    public function player() : HasOne
    {
        return $this->hasOne(Player::class, 'identifier', 'identifier');
    }

}
