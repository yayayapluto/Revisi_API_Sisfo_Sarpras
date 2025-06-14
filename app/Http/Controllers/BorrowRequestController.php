<?php

namespace App\Http\Controllers;

use App\Custom\Formatter;
use App\Models\BorrowDetail;
use App\Models\BorrowRequest;
use App\Models\ItemUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\BorrowRequestExport;

class BorrowRequestController extends Controller
{
    private $currentUserId;

    public function __construct()
    {
        $currentUser = \request()->user;
        if ($currentUser->role === "user") {
            $this->currentUserId = $currentUser->id;
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $borrowRequestQuery = BorrowRequest::query()->with("returnRequest");

        $validColumns = [
            "return_date_expected",
            "status",
            "user_id",
            "handled_by"
        ];

        $validRelation = [
            "user",
            "handler",
            "borrowDetails",
            "returnRequest"
        ];

        if (!is_null($this->currentUserId)) {
            $borrowRequestQuery->where('user_id', $this->currentUserId);
            $validRelation = array_diff($validRelation, ["user"]);
            $validColumns = array_diff($validColumns, ["user_id"]);
        }

        if (\request()->filled("with")) {
            $relations = explode(",", trim(\request()->with));
            foreach ($relations as $relation) {
                if (in_array($relation, $validRelation)) {
                    $borrowRequestQuery = $borrowRequestQuery->with($relation);
                }
            }
        }

        foreach (request()->except(['page', 'size', 'sortBy', 'sortDir', 'with']) as $key => $value) {
            if (in_array($key, $validColumns)) {
                $borrowRequestQuery->where($key, $value);
            }
        }

        $sortBy = in_array(request()->sortBy, $validColumns) ? request()->sortBy : 'created_at';
        $sortDir = strtolower(request()->sortDir) === 'desc' ? 'DESC' : 'ASC';
        $borrowRequestQuery->orderBy($sortBy, $sortDir);

        $size = min(max(request()->size ?? 10, 1), 100);
        $borrowRequests = $borrowRequestQuery->simplePaginate($size);

        return Formatter::apiResponse(200, "Borrow Requests list retrieved", $borrowRequests);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "return_date_expected" => "required|date|after:today",
            "notes" => "sometimes|string",
            "sku_list" => "required|string",
            "quantity_list" => "required|string",
            "quantity" => "sometimes|int|min:1",
            "borrow_location" => "required|string"
        ]);

        if ($validator->fails()) {
            return Formatter::apiResponse(422, "Validation failed", null, $validator->errors()->all());
        }

        $validated = $validator->validated();

        DB::beginTransaction();
        try {
            if (BorrowRequest::query()->where("user_id", $this->currentUserId)->where("status", "pending")->exists()) {
                DB::rollBack();
                return Formatter::apiResponse(400, "Please wait for previous borrow request checked by admin");
            }

            $newBorrowRequest = BorrowRequest::query()->create([
                "return_date_expected" => $validated["return_date_expected"],
                "notes" => $validated["notes"] ?? null,
                "user_id" => $this->currentUserId,
                "borrow_location" => $validated["borrow_location"]
            ]);

            $validated["quantity_list"] = explode(",", $validated["quantity_list"]);

            foreach (explode(",", $validated["sku_list"]) as $index => $sku) {
                $itemUnit = ItemUnit::query()->where("sku", $sku)->first();
                if (is_null($itemUnit)) {
                    return Formatter::apiResponse(404, "Item unit not found");
                }

                if ($itemUnit->item->type === "non-consumable") $validated["quantity_list"][$index] = 1;
                if ($itemUnit->quantity === 0 || $itemUnit->quantity < $validated["quantity_list"][$index] ?? 1) {
                    DB::rollBack();
                    return Formatter::apiResponse(400, "itemUnit with sku:" . $sku . " quantity is lower than requested");
                }

                if ($itemUnit->status !== "available") {
                    DB::rollBack();
                    return Formatter::apiResponse(400, "itemUnit not available");
                }

                BorrowDetail::query()->create([
                    "quantity" => $validated["quantity_list"][$index],
                    "borrow_request_id" => $newBorrowRequest->id,
                    "item_unit_id" => $itemUnit->id
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return Formatter::apiResponse(500, "Something wrong", null, $e->getMessage());
        }

        return Formatter::apiResponse(200, "Borrow request sent, please wait for admin approval", $newBorrowRequest->load("borrowDetails.itemUnit"));
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        $borrowRequestQuery = BorrowRequest::query()->with(["user","handler","borrowDetails.itemUnit.item","returnRequest"]);

        if (!is_null($this->currentUserId)) {
            $borrowRequestQuery->where("user_id", $this->currentUserId);
        }

        $borrowRequest = $borrowRequestQuery->find($id);
        foreach ($borrowRequest->borrowDetails as $detail) {
            $detail->itemUnit->item->image_url = url($detail->itemUnit->item->image_url);
        }

        if (is_null($borrowRequest)) {
            return Formatter::apiResponse(404, "Borrow request not found");
        }
        return Formatter::apiResponse(200, "Borrow request found", $borrowRequest);
    }

    public function approve(int $id)
    {
        $borrowRequest = BorrowRequest::query()->find($id);
        if (is_null($borrowRequest)) {
            return Formatter::apiResponse(404, "Borrow request not found");
        }
        if ($borrowRequest->status === "approved") {
            return Formatter::apiResponse(400, "Borrow request already approved");
        }

        $borrowDetails = $borrowRequest->borrowDetails;
        foreach ($borrowDetails as $borrowDetail) {
            $itemUnit = $borrowDetail->itemUnit;
            if ($itemUnit->item->type === 'consumable') {
                $newQty = $itemUnit->quantity - $borrowDetail->quantity;
                $itemUnit->quantity = $newQty;
                $itemUnit->status = $newQty <= 0 ? 'unavailable' : 'available';
                $itemUnit->current_location = $borrowRequest->borrow_location;
                $itemUnit->save();
            } else {
                $itemUnit->update([
                    "status" => "borrowed",
                    "current_location" => $borrowRequest->borrow_location
                ]);
            }
        }

        $borrowRequest->update([
            "status" => "approved",
            "handled_by" => Auth::guard("sanctum")->user()->id
        ]);
        return Formatter::apiResponse(200, "Borrow request approved", $borrowRequest->getChanges());
    }

    public function reject(int $id)
    {
        $borrowRequest = BorrowRequest::query()->find($id);
        if (is_null($borrowRequest)) {
            return Formatter::apiResponse(404, "Borrow request not found");
        }
        if ($borrowRequest->status === "rejected") {
            return Formatter::apiResponse(400, "Borrow request already rejected");
        }
        $borrowRequest->update([
            "status" => "rejected",
            "handled_by" => Auth::guard("sanctum")->user()->id
        ]);
        return Formatter::apiResponse(200, "Borrow request rejected", $borrowRequest->getChanges());
    }

    public function exportPdf(Request $request)
    {
        $start = $request->query('start');
        $end = $request->query('end');
        if (!$start || !$end) {
            return Formatter::apiResponse(422, 'Start and end date are required');
        }
        $fileName = 'borrow_requests_' . Formatter::makeDash($start) . '_to_' . Formatter::makeDash($end) . '.xlsx';
        $filePath = 'reports/' . $fileName;
        try {
            Excel::store(new BorrowRequestExport($start, $end), $filePath, "public");
        } catch (\Exception $e) {
            return Formatter::apiResponse(500, 'Failed to store excel file', null, $e->getMessage());
        }
        return Formatter::apiResponse(200, 'Borrow request report saved successfully', url('storage/reports/' . $fileName));
    }
}
