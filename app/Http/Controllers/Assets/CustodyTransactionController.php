<?php

namespace App\Http\Controllers\Assets;

use App\Domain\Assets\Actions\ConfirmCustodyReceiptAction;
use App\Domain\Assets\Actions\FinalizeIssueAction;
use App\Domain\Assets\Actions\RequestAssetReturnAction;
use App\Domain\Assets\Actions\ReturnCustodyAction;
use App\Domain\Assets\Actions\SaveIssueDraftAction;
use App\Domain\Assets\Actions\TransferCustodyAction;
use App\Domain\Assets\Exceptions\AssetDomainException;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\CustodyTransaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\IssueCustodyRequest;
use App\Http\Requests\Assets\ReturnCustodyRequest;
use App\Http\Requests\Assets\TransferCustodyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

/**
 * ASSETS-01: issue/transfer/return mutations. Every method re-checks the
 * real Policy (`$this->authorize()`), never relies on a hidden UI button as
 * the authorization boundary. Domain exceptions are translated into a
 * friendly Georgian validation error instead of an uncaught 500, matching
 * this session's Companies-module precedent.
 */
class CustodyTransactionController extends Controller
{
    public function issue(
        IssueCustodyRequest $request,
        Asset $asset,
        SaveIssueDraftAction $saveDraft,
        FinalizeIssueAction $finalize,
    ): RedirectResponse {
        $this->authorize('assets.custody.manage');

        try {
            $draft = $saveDraft->execute(null, $request->validated() + [
                'lines' => $request->validated('lines') ?: [['asset_id' => $asset->id]],
            ], $request->user());

            $finalize->execute($draft, $request->user());
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['custody' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('assets.show', $asset)->with('success', 'აქტივი გაცემულია — ველოდებით მიღების დადასტურებას.');
    }

    public function confirmReceipt(CustodyTransaction $transaction, ConfirmCustodyReceiptAction $action): RedirectResponse
    {
        $this->authorize('confirmReceipt', $transaction);

        try {
            $action->execute($transaction, request()->user());
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['custody' => $exception->getMessage()]);
        }

        $assetId = $transaction->lines()->value('asset_id');

        return Redirect::route('assets.show', $assetId)->with('success', 'მიღება დადასტურდა.');
    }

    public function transfer(TransferCustodyRequest $request, Asset $asset, TransferCustodyAction $action): RedirectResponse
    {
        $this->authorize('assets.custody.manage');

        try {
            $action->execute($asset, $request->validated(), $request->user());
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['custody' => $exception->getMessage()])->withInput();
        }

        return Redirect::route('assets.show', $asset)->with('success', 'გადაცემა დარეგისტრირდა — ველოდებით მიღების დადასტურებას.');
    }

    public function requestReturn(CustodyTransaction $transaction, RequestAssetReturnAction $action): RedirectResponse
    {
        $this->authorize('requestReturn', $transaction);

        try {
            $action->execute($transaction, request()->user());
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['custody' => $exception->getMessage()]);
        }

        $assetId = $transaction->lines()->value('asset_id');

        return Redirect::route('assets.show', $assetId)->with('success', 'დაბრუნების მოთხოვნა გაიგზავნა.');
    }

    public function return(ReturnCustodyRequest $request, CustodyTransaction $transaction, ReturnCustodyAction $action): RedirectResponse
    {
        $this->authorize('manage', $transaction);

        try {
            $action->execute(
                $transaction,
                $request->validated('lines'),
                $request->user(),
                $request->validated('comment'),
                $request->validated('receiving_warehouse_id'),
            );
        } catch (AssetDomainException $exception) {
            return back()->withErrors(['custody' => $exception->getMessage()])->withInput();
        }

        $assetId = $transaction->lines()->value('asset_id');

        return Redirect::route('assets.show', $assetId)->with('success', 'დაბრუნება დარეგისტრირდა.');
    }
}
