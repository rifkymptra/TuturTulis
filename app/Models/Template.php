<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    protected $fillable = ['title', 'file_path'];

    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }
}
