<?php
namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProfileVerification extends Model
{
    use HasFactory;

    /**
     * Los atributos que son asignables en masa.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',       // ID del usuario que envió la solicitud
        'dni_image_path', // Ruta de la imagen del DNI en el servidor
        'status',         // Estado de la solicitud: pending, approved, rejected
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Obtener el usuario asociado con la solicitud de verificación.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}