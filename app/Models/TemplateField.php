<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateField extends Model
{
    protected $fillable = ['template_id', 'field_name', 'field_label', 'field_type'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
