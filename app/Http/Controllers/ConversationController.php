<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConversationService $conversations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $conversations = $this->conversations->paginate($request->only(['status', 'q', 'per_page']));

        return $this->sendSuccess(
            ConversationResource::collection($conversations)->response()->getData(true)
        );
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation = $this->conversations->withTranscript($conversation);
        $conversation->tokens_used = $this->conversations->tokensUsed($conversation);

        return $this->sendSuccess(new ConversationResource($conversation));
    }
}
