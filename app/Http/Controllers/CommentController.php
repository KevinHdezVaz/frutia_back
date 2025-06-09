<?php
namespace App\Http\Controllers;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommentController extends Controller {
    public function index($matchId) {
        $comments = Comment::where('match_id', $matchId)
            ->with('user:id,name,profile_image')
            ->latest()
            ->get();
        return response()->json($comments);
    }

    public function store(Request $request, $matchId) {
        $request->validate(['text' => 'required|string|max:500']);
        $comment = Comment::create([
            'match_id' => $matchId,
            'user_id' => Auth::id(),
            'text' => $request->text,
        ]);
        return response()->json($comment->load('user'), 201);
    }
}