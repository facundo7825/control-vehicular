<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $datos = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $request->user()->update(['token_push' => $datos['token']]);

        return response()->noContent();
    }
}
