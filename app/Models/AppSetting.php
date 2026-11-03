<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** A setting the support account changes in the app. Secret values are stored encrypted with the app key. */
class AppSetting extends Model
{
    protected $primaryKey = 'name';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['name', 'value', 'updated_by'];

    public static function read(string $name, bool $secret = false): ?string
    {
        $v = static::find($name)?->value;
        if ($v === null || ! $secret) {
            return $v;
        }
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable) {
            return null;  // made with another app key: treated as not set
        }
    }

    public static function write(string $name, string $value, string $by, bool $secret = false): void
    {
        static::updateOrCreate(['name' => $name], ['value' => $secret ? Crypt::encryptString($value) : $value, 'updated_by' => $by]);
    }
}
