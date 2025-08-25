<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_session_id',
        'user_id',
        'text',
        'image_url', // <-- AÑADE ESTA LÍNEA
        'is_user',
        'created_at',
        'updated_at'
    ];
    protected $visible = [
        'id',
        'chat_session_id',
        'user_id',
        'text',
        'is_user',
         'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_user' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function chatSession()
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}