<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserService $userService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->paginate($request->only(['q', 'role', 'per_page']));

        return $this->sendSuccess(
            UserResource::collection($users)->response()->getData(true)
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->sendSuccess(new UserResource($user), 'Staff member created', 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->user(), $request->validated());

        return $this->sendSuccess(new UserResource($user), 'Staff member updated');
    }

    public function toggleActive(Request $request, User $user): JsonResponse
    {
        $user = $this->userService->toggleActive($user, $request->user());

        return $this->sendSuccess(
            new UserResource($user),
            $user->active ? 'Account activated' : 'Account deactivated'
        );
    }
}
