<?php

namespace App\Http\Controllers\Api;

use App\Actions\Itp\AcceptAttunementAction;
use App\Actions\Itp\ChooseSectionVersionAction;
use App\Actions\Itp\FlagSectionAction;
use App\Actions\Itp\SubmitFollowupsAction;
use App\Actions\Itp\SubmitIntakeAction;
use App\Enums\ItpSectionChoice;
use App\Exceptions\GenerationNotReady;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Itp\StoreItpFollowupAnswersRequest;
use App\Http\Requests\Api\Itp\StoreItpIntakeRequest;
use App\Http\Requests\Api\Itp\StoreItpSectionChoiceRequest;
use App\Http\Requests\Api\Itp\StoreItpSectionFlagRequest;
use App\Http\Resources\ItpGenerationResource;
use App\Http\Responses\ApiResponse;
use App\Models\ItpGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The generation flow for both app builds (platform.md §6, step 6 and sections contracts). Routes
 * check ownership through ItpGenerationPolicy; a status that doesn't accept the action is a 422.
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

    /**
     * Flag one section (`{part}`) and queue its rewrite. A section the attunement doesn't have is
     * a 404.
     */
    public function storeSectionFlag(StoreItpSectionFlagRequest $request, ItpGeneration $generation, int $part, FlagSectionAction $flagSection): JsonResponse
    {
        abort_unless($generation->attunement?->hasSection($part), 404);

        try {
            $generation = $flagSection->execute(
                $generation,
                $part,
                $request->validated('user_explanation'),
                $request->validated('requested_change'),
                $request->validated('segment_id'),
            );
        } catch (GenerationNotReady $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return $this->generationResponse('Rewriting the section.', $generation, 202);
    }

    /**
     * Keep the rewritten section (`new`) or the original. A section the attunement doesn't have
     * is a 404.
     */
    public function storeSectionChoice(StoreItpSectionChoiceRequest $request, ItpGeneration $generation, int $part, ChooseSectionVersionAction $chooseSectionVersion): JsonResponse
    {
        abort_unless($generation->attunement?->hasSection($part), 404);

        try {
            $generation = $chooseSectionVersion->execute($generation, $part, $request->enum('keep', ItpSectionChoice::class));
        } catch (GenerationNotReady $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return $this->generationResponse('Choice saved.', $generation);
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
