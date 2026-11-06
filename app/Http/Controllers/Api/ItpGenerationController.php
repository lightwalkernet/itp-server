<?php

namespace App\Http\Controllers\Api;

use App\Actions\Itp\AcceptAttunementAction;
use App\Actions\Itp\FlagSegmentAction;
use App\Actions\Itp\SubmitFollowupsAction;
use App\Actions\Itp\SubmitIntakeAction;
use App\Exceptions\GenerationNotReady;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Itp\StoreItpFollowupAnswersRequest;
use App\Http\Requests\Api\Itp\StoreItpIntakeRequest;
use App\Http\Requests\Api\Itp\StoreItpSegmentFlagRequest;
use App\Http\Resources\ItpGenerationResource;
use App\Http\Responses\ApiResponse;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The generation flow for both app builds (platform.md §6, step 6 contract). Routes check
 * ownership through ItpGenerationPolicy; a status that doesn't accept the action is a 422.
 */
class ItpGenerationController extends Controller
{
    /**
     * The user's generations, newest first, as summaries.
     */
    public function index(Request $request): JsonResponse
    {
        $generations = ItpGeneration::query()
            ->whereBelongsTo($request->user())
            ->latest()
            ->latest('id')
            ->get();

        return ApiResponse::success('Generations.', [
            'generations' => $generations->map(fn (ItpGeneration $generation) => ItpGenerationResource::make($generation)->summary())->all(),
        ]);
    }

    public function store(StoreItpIntakeRequest $request, SubmitIntakeAction $submitIntake): JsonResponse
    {
        $generation = $submitIntake->execute($request->user(), $request->validated());

        return $this->generationResponse('Generation queued.', $generation, 201);
    }

    public function show(ItpGeneration $generation): JsonResponse
    {
        return $this->generationResponse('Generation.', $generation);
    }

    public function storeFollowups(StoreItpFollowupAnswersRequest $request, ItpGeneration $generation, SubmitFollowupsAction $submitFollowups): JsonResponse
    {
        try {
            $generation = $submitFollowups->execute($generation, $request->validated('answers'));
        } catch (GenerationNotReady $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return $this->generationResponse('Answers received.', $generation, 202);
    }

    public function storeFlag(StoreItpSegmentFlagRequest $request, ItpGeneration $generation, ItpAttunementSegment $segment, FlagSegmentAction $flagSegment): JsonResponse
    {
        try {
            $generation = $flagSegment->execute(
                $generation,
                $segment,
                $request->validated('user_explanation'),
                $request->validated('requested_change'),
            );
        } catch (GenerationNotReady $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return $this->generationResponse('Regenerating the segment.', $generation, 202);
    }

    public function accept(ItpGeneration $generation, AcceptAttunementAction $acceptAttunement): JsonResponse
    {
        try {
            $generation = $acceptAttunement->execute($generation);
        } catch (GenerationNotReady $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return $this->generationResponse('Attunement accepted.', $generation, 202);
    }

    /**
     * The generation as stored now. On a sync queue (tests, local) the job has already run.
     */
    protected function generationResponse(string $message, ItpGeneration $generation, int $status = 200): JsonResponse
    {
        $generation = $generation->fresh(['attunement.currentSegments', 'companions']);

        return ApiResponse::success($message, [
            'generation' => ItpGenerationResource::make($generation),
        ], $status);
    }
}
