<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $table = 'mcp_notification_deliveries';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attempted_at' => 'immutable_datetime'];
    }
}
