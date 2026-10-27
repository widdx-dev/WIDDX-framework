<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;

/**
 * User controller — full CRUD in minimal code.
 *
 * Validation lives on the model ($rules): create() validates automatically
 * and failures redirect back with errors + old input — no try/catch needed.
 */
class UserController
{
    public function index(User $user)
    {
        $users = $user->all();
        return view('users.index', ['users' => $users]);
    }

    public function show($id)
    {
        $user = User::findOrFail($id);
        return view('users.show', compact('user'));
    }

    public function create()
    {
        return view('users.create');
    }

    public function store()
    {
        User::create(request()->only(['name', 'email']));
        return redirect('/users');
    }

    public function edit($id)
    {
        $user = User::findOrFail($id);
        return view('users.edit', compact('user'));
    }

    public function update($id)
    {
        $user = User::findOrFail($id);
        $user->fill(request()->only(['name', 'email']));
        $user->save();
        return redirect('/users');
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return redirect('/users');
    }
}
