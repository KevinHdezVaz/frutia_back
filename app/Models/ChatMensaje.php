<?php

namespace App\Models;

use App\Models\User;
use App\Models\ChatMensaje;
use Illuminate\Database\Eloquent\Model;

class ChatMensaje extends Model
{
    protected $fillable = [
        'equipo_id',
        'user_id',
        'mensaje',
        'file_url',
        'file_type',
        'file_name',
          'reply_to_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }


    public function replyTo()
    {
        return $this->belongsTo(ChatMensaje::class, 'reply_to_id');
    }
}