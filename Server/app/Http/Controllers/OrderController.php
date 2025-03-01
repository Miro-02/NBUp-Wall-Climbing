<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderRequest;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Cart;
use App\Models\OrderProductStatus;
use App\Models\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController
{
    public function store(Request $request)
    {
        $user = $request->user();

        // Get user's cart items
        $cart = Cart::where('user_id', $user->id)->first();

        if (!$cart || $cart->products->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 400);
        }

        // Get default order status (e.g., 'pending')
        $status = OrderStatus::where('name', 'pending')->firstOrFail();

        // Create the order
        $order = Order::create([
            'user_id' => $user->id,
            'status_id' => $status->id,
        ]);

        $orderProductsStatus = OrderProductStatus::where('name', 'pending')->firstOrFail();

        foreach ($cart->products as $product) {
            $orderProduct = OrderProduct::create([
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'seller_id' => $product->seller_id,
                'quantity' => $cart->products()->where('product_id', $product->id)->first()->pivot->quantity,
                'popularity' => $product->popularity,
                'order_product_status_id' => $orderProductsStatus->id,
            ]);

            $order->orderProducts()->attach(
                $orderProduct->id
            );
        }

        $cart->products()->detach();

        return response()->json($order->load('orderProducts'), 201);
    }

    public function myOrders(Request $request)
    {
        $user = $request->user();
        $orders = Order::where('user_id', $user->id)
            ->with(['orderProducts', 'status'])
            ->get();

        return response()->json($orders);
    }

    public function myOrder($id)
    {
        $user = Auth::user();
        $order = Order::where('user_id', $user->id)
            ->with(['orderProducts', 'status'])
            ->findOrFail($id);
        return response()->json($order);
    }

    public function index()
    {
        $orders = Order::with(['user', 'orderProducts', 'status'])
            ->get();

        return response()->json($orders);
    }

    public function show($id)
    {
        $order = Order::with(['user', 'orderProducts', 'status'])
            ->findOrFail($id);

        return response()->json($order);
    }

    public function advanceStatus($id)
    {
        $order = Order::with(['orderProducts.status', 'status'])->findOrFail($id);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $currentStatus = $order->status->slug;

        switch ($currentStatus) {
            case 'pending':
                $nextStatusSlug = 'confirmed';
                break;
            case 'confirmed':
                $nextStatusSlug = 'processing';
                break;
            case 'processing':
                $nextStatusSlug = 'shipped';
                break;
            case 'shipped':
                $nextStatusSlug = 'delivered';
                break;
            case 'delivered':
                return response()->json(['error' => 'Order is already delivered'], 400);
            case 'denied':
                return response()->json(['error' => 'Order is denied'], 400);
            default:
                return response()->json(['error' => 'Invalid order status'], 400);
        }

        $nextStatus = OrderStatus::where('slug', $nextStatusSlug)->firstOrFail();

        if (!$this->areAllOrderProductsStatus($order->orderProducts, $currentStatus)) {
            return response()->json(['error' => 'Not all order products are in the required status'], 400);
        }

        $order->update(['status_id' => $nextStatus->id]);

        return response()->json($order->load('orderProducts.status', 'status'));
    }

    private function areAllOrderProductsStatus($orderProducts, $currentStatusName)
    {
        foreach ($orderProducts as $orderProduct) {
            if ($orderProduct->status->slug !== $currentStatusName) {
                return false;
            }
        }
        return true;
    }
}