<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A district's surveillance officer, who receives the SMS about the district's due and overdue follow-ups. */
class DistrictOfficer extends Model
{
    protected $fillable = ['region', 'district_code', 'district', 'name', 'phone', 'updated_by'];
}
