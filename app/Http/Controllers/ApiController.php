<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Merchandiser;
use App\Order;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    private function sign($text, $private_key)
    {
        $signature = '';

        openssl_sign($text, $signature, $private_key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function verify($data, $public_key)
    {
        if (empty($data['sign'])) {
            return false;
        } else {
            $signature = $data['sign'];
        }

        if (!empty($data['timestamp']) && time() - 60 <= $data['timestamp']) {
            unset($data['items']);
            unset($data['sign']);

            reset($data);
            ksort($data);

            try {
                return openssl_verify(
                    http_build_query($data),
                    base64_decode($signature),
                    $public_key,
                    OPENSSL_ALGO_SHA256
                );
            } catch (Exception $e) {
                return false;
            }
        } else {
            return false;
        }
    }

    private function jsonFormat($data, $message = '', $status = 0)
    {
        return (json_encode([
            'data'    => $data,
            'message' => $message,
            'status'  => $status,
        ]));
    }

    public function submitOrder(Request $request)
    {
        $this->validate($request, [
            'merchandiser_id' => 'required|integer',
            'trade_no'        => 'required|max:255',
            'subject'         => 'required|max:255',
            'amount'          => 'required|numeric',
            'returnUrl'       => 'required|url',
            'notifyUrl'       => 'required|url',
            'items'           => 'array',
        ]);

        $data = $request->all();

        $merch = Merchandiser::where('status', 'alive')->findOrFail($data['merchandiser_id']);

        if ($this->verify($data, $merch['pubkey'])) {
            $order = Order::where('merchandiser_id', $merch['id'])->where('trade_no', $data['trade_no'])->first();
            if (empty($order)) {
                if (parse_url($data['returnUrl'], PHP_URL_HOST) != $merch['domain'] ||
                    parse_url($data['notifyUrl'], PHP_URL_HOST) != $merch['domain']) {
                    return $this->jsonFormat(null, 'Your URL must belongs to domain "' . $merch['domain'] . '"', '400');
                }

                $order = Order::create([
                    'merchandiser_id' => $data['merchandiser_id'],
                    'trade_no'        => $data['trade_no'],
                    'subject'         => $data['subject'],
                    'amount'          => $data['amount'],
                    'items'           => serialize($data['items']),
                    'returnUrl'       => $data['returnUrl'],
                    'notifyUrl'       => $data['notifyUrl'],
                ]);

                $order->items = unserialize($order->items);

                return $this->jsonFormat($order);
            } elseif ($order->status == 'pending') {
                $order->update([
                    'subject' => $data['subject'],
                    'amount'  => $data['amount'],
                    'items'   => serialize($data['items']),
                ]);

                $order->items = unserialize($order->items);

                return $this->jsonFormat($order);
            } else {
                return $this->jsonFormat(null, 'trade_no already exsits', '409');
            }
        } else {
            return $this->jsonFormat(null, 'Signature Invalid or timestamp expired', '403');
        }
    }

    public function getOrder(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $data = $request->all();

        $merch = Merchandiser::findOrFail($order['merchandiser_id']);

        if ($this->verify($data, $merch['pubkey'])) {
            return $this->jsonFormat($order);
        } else {
            return $this->jsonFormat(null, 'Signature Invalid or timestamp expired', '403');
        }
    }

    public function completeOrder(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $data = $request->all();

        $merch = Merchandiser::findOrFail($order['merchandiser_id']);

        if (!empty($data['trade_no']) && $data['trade_no'] === $order->trade_no) {
            if ($this->verify($data, $merch['pubkey'])) {
                if ($order->status === 'processing') {
                    $order->status = 'done';
                    $order->save();
                }

                return $this->jsonFormat($order);
            } else {
                return $this->jsonFormat(null, 'Signature Invalid or timestamp expired', '403');
            }
        } else {
            return $this->jsonFormat(null, 'trade_no not matched', '404');
        }
    }

    public function removeOrder(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $data = $request->all();

        $merch = Merchandiser::findOrFail($order['merchandiser_id']);

        if (!empty($data['trade_no']) && $data['trade_no'] === $order->trade_no) {
            if ($this->verify($data, $merch['pubkey'])) {
                if (in_array($order->status, ['refunded', 'cancelled'])) {
                    $order->delete();

                    return $this->jsonFormat(null);
                } else {
                    return $this->jsonFormat(null, 'Cannot delete this order', '405');
                }
            } else {
                return $this->jsonFormat(null, 'Signature Invalid or timestamp expired', '403');
            }
        } else {
            return $this->jsonFormat(null, 'trade_no not matched', '404');
        }
    }
}
