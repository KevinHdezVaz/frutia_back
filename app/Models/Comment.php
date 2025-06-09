<?php
namespace App\Models;

use App\Models\User;
use App\Models\EquipoPartido;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model {
    protected $fillable = ['match_id', 'user_id', 'text'];
    public function user() { return $this->belongsTo(User::class); }
    public function match() { return $this->belongsTo(EquipoPartido::class, 'match_id'); }
}