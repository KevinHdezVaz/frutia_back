<?php


use App\Models\Administrator;
use Illuminate\Database\Eloquent\Model;

class Stories extends Model
{
    protected $fillable = [
        'title',
        'image_url',
        'video_url',
        'is_active',
        'expires_at',
        'administrator_id'
    ];

    // Especifica que estos campos deben ser tratados como fechas
    protected $dates = [
        'expires_at',
        'created_at',
        'updated_at'
    ];

    // O alternativamente puedes usar esta sintaxis más moderna:
    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean'
    ];

    public function administrator()
    {
        return $this->belongsTo(Administrator::class);
    }
}