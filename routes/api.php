<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\DriversController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\FactoriesController;
use App\Http\Controllers\ShippingLineController;
use App\Http\Controllers\ShipOrderDataController;
use App\Http\Controllers\OperatingOrderController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\TransportReceiptController;
use App\Http\Controllers\TreasuryController;
use App\Http\Controllers\TreasuryOperationsController;
use App\Http\Controllers\DriverExtraController;
use App\Http\Controllers\ActivityLogController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/login', [AuthController::class, 'login']);


Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::get('/user', function (Request $request) {
            return response()->json($request->user()->load('roles', 'permissions', 'treasuries'));
        });

        Route::get('/users', [AuthController::class, 'getUsers']);
        Route::post('/users', [AuthController::class, 'createUser']);
        Route::put('/users/{id}', [AuthController::class, 'editUser']);
        Route::delete('/users/{id}', [AuthController::class, 'deleteUser']);

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::prefix('roles-permissions')->group(function () {

            Route::get('/roles', [RolePermissionController::class, 'roles']);

            Route::get('/permissions', [RolePermissionController::class, 'permissions']);
        });
    });


    Route::apiResource('vehicles', VehicleController::class);
    Route::apiResource('drivers', DriversController::class);
    Route::apiResource('clients', ClientsController::class);
    Route::apiResource('factories', FactoriesController::class);
    Route::apiResource('destinations', DestinationController::class);
    Route::apiResource('shipping-lines', ShippingLineController::class);

    // Handel Treasury data methods
    Route::apiResource('/treasuries', TreasuryController::class);
    Route::post('/treasury/deposit', [TreasuryOperationsController::class, 'deposit']);
    Route::post('/treasury/send', [TreasuryOperationsController::class, 'send']);
    Route::post('/treasury/deduction', [TreasuryOperationsController::class, 'deduction']);
    Route::post('/treasury/update/deduction/{id}', [TreasuryOperationsController::class, 'updateDeduction']);
    Route::post('/treasury/delete/deduction/{id}', [TreasuryOperationsController::class, 'destroyDeduction']);
    Route::post('/treasury/shift-handle', [TreasuryOperationsController::class, 'shiftHandle']);
    Route::get('/order-receipts/categories', [\App\Http\Controllers\OrderReceiptController::class, 'categories']);
    Route::post('/order-receipts/{id}/cancel', [\App\Http\Controllers\OrderReceiptController::class, 'cancel']);
    Route::apiResource('/order-receipts', \App\Http\Controllers\OrderReceiptController::class)->except('destroy');

    // Handle Transport Receipt
    Route::apiResource('/transport-receipts', TransportReceiptController::class);

    // Handel policies data methods
    Route::apiResource('/policies', PolicyController::class);

    // Handel driver extras data methods
    Route::apiResource('/driver-extras', DriverExtraController::class);
    Route::get('/vehicle-driver-assignments/{assignmentId}/driver-extras', [DriverExtraController::class, 'getByAssignment']);
    Route::get('/get-assignment/ship-order/{shipOrderId}/policy/{policyId}', [PolicyController::class, 'getAssignmentByShipOrderAndPolicy']);

    Route::post('/reports/generate-share-link', [ReportsController::class, 'GenerateShareLink']);
    Route::post('/reports/vehicle-statements/clearance', [DriverExtraController::class, 'settle']);

    // Ship order number generation
    Route::get('/ship-order-number', [ShipOrderDataController::class, 'generateOrderNumber']);

    // Handel ship order data methods
    Route::apiResource('/ship-order-data', ShipOrderDataController::class);

    // Handel Operating orders data methods
    Route::apiResource('/operating-orders', OperatingOrderController::class);

    // Policy number generation
    Route::get('/policy-number', [PolicyController::class, 'generatePolicyNumber']);

    // Get policies by ship order data
    Route::get('/ship-order-data/{shipOrderDataId}/policies', [PolicyController::class, 'getByShipOrderData']);

    // Vehicle assignment management
    Route::post('/policies/{policyId}/vehicle-assignments', [PolicyController::class, 'addVehicleAssignment']);
    Route::put('/vehicle-assignments/{assignmentId}', [PolicyController::class, 'updateVehicleAssignment']);
    Route::delete('/vehicle-assignments/{assignmentId}', [PolicyController::class, 'removeVehicleAssignment']);

    // Container management for vehicle assignments
    Route::post('/vehicle-assignments/{assignmentId}/containers', [PolicyController::class, 'addContainersToAssignment']);
    Route::delete('/vehicle-assignments/{assignmentId}/containers', [PolicyController::class, 'removeContainersFromAssignment']);

    // Activity Log API for frontend
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::get('/activity-logs/log-names', [ActivityLogController::class, 'logNames']);
    Route::get('/activity-logs/stats', [ActivityLogController::class, 'stats']);
    Route::get('/activity-logs/{id}', [ActivityLogController::class, 'show']);

    // Reports
    Route::get('/reports/vehicle/{number}', [ReportsController::class, 'vehicleReport']);
    Route::get('/reports/torrents/{number}', [ReportsController::class, 'torrentsReports']);
    Route::get('/reports/loading-withdrawal/{number}', [ReportsController::class, 'LoadingWithdrawalReport']);
    Route::get('/reports/client-account-statements', [ReportsController::class, 'clientAccountStatements']);
    Route::get('/reports/vehicle-statements', [ReportsController::class, 'vehicleStatement']);
    Route::get('/reports/dashboard', [ReportsController::class, 'dashboard']);
});

Route::get('/reports/share-links/{serial}', [ReportsController::class, 'getSharedReport']);
