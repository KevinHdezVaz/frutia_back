<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatSession extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Los atributos que son asignables en masa.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'title',
        'is_saved',
        'created_at',
        'updated_at',
    ];

    /**
     * Los atributos que deberían estar visibles en las respuestas JSON.
     *
     * @var array
     */
    protected $visible = [
        'id',
        'user_id',
        'title',
        'is_saved',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Los atributos que deberían ser casteados a tipos nativos.
     *
     * @var array
     */
    protected $casts = [
        'is_saved' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Relación con los mensajes.
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Relación con el usuario.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}