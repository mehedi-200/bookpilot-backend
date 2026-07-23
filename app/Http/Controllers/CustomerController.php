<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicatePhoneException;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CustomerService $customerService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $customers = $this->customerService->paginate($request->only(['q', 'per_page']));

        return $this->sendSuccess(
            CustomerResource::collection($customers)->response()->getData(true)
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        try {
            $customer = $this->customerService->create($request->validated());
        } catch (DuplicatePhoneException $e) {
            return $this->duplicatePhoneError($e);
        }

        return $this->sendSuccess(new CustomerResource($customer), 'Customer created', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        // One request for the whole timeline page.
        $customer->loadCount('bookings')->load([
            'bookings' => fn ($q) => $q->with('service')->limit(10),
            'conversations' => fn ($q) => $q->limit(5),
        ]);

        return $this->sendSuccess(new CustomerResource($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $customer = $this->customerService->update($customer, $request->validated());
        } catch (DuplicatePhoneException $e) {
            return $this->duplicatePhoneError($e);
        }

        return $this->sendSuccess(new CustomerResource($customer), 'Customer updated');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customerService->delete($customer);

        return $this->sendSuccess(null, 'Customer deleted');
    }

    public function lookup(Request $request): JsonResponse
    {
        $customer = $this->customerService->findByPhone($request->query('phone'));

        return $this->sendSuccess($customer ? new CustomerResource($customer) : null);
    }

    private function duplicatePhoneError(DuplicatePhoneException $e): JsonResponse
    {
        // Rich 422: the frontend turns this into "belongs to X → open profile".
        return $this->sendError($e->getMessage(), 422, [
            'phone' => ["This phone number already belongs to {$e->existing->name}."],
            'existing_customer' => [
                'id' => $e->existing->id,
                'name' => $e->existing->name,
            ],
        ]);
    }
}
