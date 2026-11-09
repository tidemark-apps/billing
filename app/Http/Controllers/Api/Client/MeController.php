<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MeController extends ApiController
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('client'));
    }

    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', Password::min(8)],
            'current_password' => ['required_with:password', 'string'],
            'client' => ['sometimes', 'array'],
            'client.name' => ['sometimes', 'string', 'max:255'],
            'client.company_name' => ['nullable', 'string', 'max:255'],
            'client.email' => ['sometimes', 'email', 'max:255'],
            'client.phone' => ['nullable', 'string', 'max:50'],
            'client.address_1' => ['nullable', 'string', 'max:255'],
            'client.address_2' => ['nullable', 'string', 'max:255'],
            'client.city' => ['nullable', 'string', 'max:255'],
            'client.state' => ['nullable', 'string', 'max:255'],
            'client.postcode' => ['nullable', 'string', 'max:30'],
            'client.country' => ['nullable', 'string', 'size:2'],
            'client.tax_id' => ['nullable', 'string', 'max:64'],
        ]);
        if (isset($data['password']) && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $user->update(collect($data)->only(['name', 'email', 'password'])->all());
        if (isset($data['client'])) {
            $client = $data['client'];
            if (isset($client['country'])) {
                $client['country'] = strtoupper($client['country']);
            }
            $user->client->update($client);
        }

        return new UserResource($user->refresh()->load('client'));
    }
}
