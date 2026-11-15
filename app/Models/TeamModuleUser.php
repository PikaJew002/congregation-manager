<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamModuleUser extends Model
{
    use BelongsToTeam;

    protected $table = 'team_module_user';

    protected $fillable = [
        'team_id',
        'user_id',
        'module_key',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
