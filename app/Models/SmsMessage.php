<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One SMS sent from the app about an AFP case, with the provider's reference and delivery status. */
class SmsMessage extends Model
{
    protected $fillable = ['epid', 'status_key', 'region', 'district', 'officer', 'phone', 'message', 'sent_by',
        'reference', 'status', 'error'];
}
