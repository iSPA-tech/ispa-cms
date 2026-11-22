<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

class CartHelper extends Facade
{
    protected Collection $cart;

    /**
     * the event dispatcher
     */
    protected mixed $events;

    /**
     * the cart session key
     */
    protected string $instanceName = 'CartHelperInstance';

    /**
     * the session key use for the cart
     */
    protected string $sessionKey;

    /**
     * Configuration to pass to ItemCollection
     */
    protected mixed $config;

    /**
     * This holds the currently added item id in cart for association
     */
    protected string $currentItemId;

    /**
     * Cart Helper constructor
     */
    public function __construct()
    {
        $this->sessionKey = 'shopping_cart';
        $this->cart = session($this->sessionKey, new Collection());

        $this->events = app('events');
    }

    /**
     * @return $this
     */
    public static function getInstance(): self
    {
        return static::getFacadeRoot();
    }

    /**
     * Add to cart
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-29, 21:34 ICT
     */
    public function add(array $item = []): false|static
    {
        if (empty($item)) {
            return false;
        }

        $cart = $this->getContent();

        if ($cart->has($item['id'])) {
            $this->update($item['id'], $item);
        } else {
            $this->addToCart($item['id'], $item['sku'], $item['name'], $item['quantity'], $item['price'], $item['attributes'] ?? [], $item['product'] ?? []);
        }

        return $this;
    }

    /**
     * get the cart
     *
     * @return Collection
     */
    public function getContent(): Collection
    {
        return $this->cart;
    }

    /**
     * Check if the item exists in the cart
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-30, 10:36 ICT
     */
    public function has(string $id): bool
    {
        return $this->getContent()->has($id);
    }

    /**
     * Update the quantity of one item of the cart
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-30, 10:37 ICT
     */
    public function update(string $id, array $data = []): bool
    {
        if (!$this->has($id)) {
            return false;
        }

        $cart = $this->getContent();

        $item = $cart->get($id);
        //  Plus the quantity
        if (isset($data['quantity'])) {
            $item['quantity'] = $data['quantity'];
        }

        $cart->put($id, $item);

        $this->save($cart);

        return true;
    }

    /**
     * Save cart to session
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-31, 16:37 ICT
     */
    public function save(mixed $data): void
    {
        session()->put($this->sessionKey, $data);
    }

    /**
     * Internal method to add item into cart
     *
     * @param string $id
     * @param string $sku
     * @param string $name
     * @param int $quantity
     * @param float $price
     * @param array $attributes
     * @param mixed $product
     *
     * @return bool
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-31, 16:35 ICT
     */
    protected function addToCart(string $id, string $sku, string $name, int $quantity, float $price, array $attributes = [], mixed $product = null): bool
    {
        $this->currentItemId = $id;

        $this->getContent()->put($id, [
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'quantity' => $quantity,
            'price' => $price,
            'attributes' => $attributes,
            'product' => $product,
        ]);

        $this->save($this->getContent());

        return true;
    }

    /**
     * Calculate the total price of the cart
     *
     * @return int|mixed
     * @author malayvuong
     * @since 7.16.1175 - 2024-03-21, 20:31 ICT
     */
    public function total(): mixed
    {
        if ($this->isEmpty()) {
            return 0;
        }

        return $this->getContent()->sum(function ($item) {
            return $item['quantity'] * $item['price'];
        });
    }

    /**
     * check if cart is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->getContent()->isEmpty();
    }

    /**
     * Total formatted price by currency
     *
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2024-03-24, 17:44 ICT
     */
    public function format(string $type = 'total'): string
    {
        if (method_exists($this, $type)) {
            $total = $this->$type();
        } else {
            $total = 0;
        }

        return format_currency($total);
    }

    /**
     * Get total quantity of cart
     *
     * @author malayvuong
     * @since  - 2023-12-30, 11:18 ICT
     */
    public function getTotalQuantity()
    {
        if ($this->isEmpty()) {
            return 0;
        }

        return $this->getContent()->sum(function ($item) {
            return $item['quantity'];
        });
    }

    /**
     * Clear the cart
     *
     * @return CartHelper
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-31, 17:25 ICT
     */
    public function clear(): self
    {
        $this->save(new Collection());

        $this->fireEvent('cleared');

        return $this;
    }

    /**
     * @param $name
     * @param array $value
     *
     * @return mixed
     */
    protected function fireEvent($name, array $value = []): mixed
    {
        return $this->events->dispatch(self::getFacadeAccessor() . '.' . $name, array_values([$value, $this]), true);
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 18:01 ICT
     */
    protected static function getFacadeAccessor(): string
    {
        return 'CartHelper';
    }

    /**
     * Get variant price
     *
     * @param array $variant
     *
     * @return float
     * @author malayvuong
     * @since 7.160.1175 - 2024-01-22, 15:45 ICT
     */
    public function getVariantPrice(array $variant = []): float
    {
        $sale_price = $variant['sale_price'] ?? 0;
        $sell_price = $variant['sell_price'] ?? 0;
        $sale_from_date = $variant['sale_from_date'] ?? null;
        $sale_to_date = $variant['sale_to_date'] ?? null;

        // Check current time between sale_from_date and sale_to_date
        if ($sale_from_date !== null && $sale_to_date !== null) {
            $now = now();
            $saleFromDate = now()->parse($sale_from_date)->setTimezone(get_timezone());
            $saleToDate = now()->parse($sale_to_date)->setTimezone(get_timezone());

            if ($now >= $saleFromDate && $now <= $saleToDate) {
                return $sale_price > 0 ? $sale_price : $sell_price;
            }
        }

        return $sell_price;
    }

    /**
     * Remove item from cart
     *
     * @param string $id
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-03-24, 18:30 ICT
     */
    public function remove(string $id = ''): void
    {
        $cart = $this->getContent();

        if ($cart->has($id)) {
            $cart->forget($id);
        }

        $this->save($cart);
    }

    /**
     * @return string
     *
     * data return: usd, aed, afn, all, amd, ang, aoa, ars, aud, awg, azn, bam, bbd, bdt, bgn, bhd, bif, bmd, bnd, bob,
     *     brl, bsd, bwp, byn, bzd, cad, cdf, chf, clp, cny, cop, crc, cve, czk, djf, dkk, dop, dzd, egp, etb, eur,
     *     fjd, fkp, gbp, gel, gip, gmd, gnf, gtq, gyd, hkd, hnl, hrk, htg, huf, idr, ils, inr, isk, jmd, jod, jpy,
     *     kes, kgs, khr, kmf, krw, kwd, kyd, kzt, lak, lbp, lkr, lrd, lsl, mad, mdl, mga, mkd, mmk, mnt, mop, mur,
     *     mvr, mwk, mxn, myr, mzn, nad, ngn, nio, nok, npr, nzd, omr, pab, pen, pgk, php, pkr, pln, pyg, qar, ron,
     *     rsd, rub, rwf, sar, sbd, scr, sek, sgd, shp, sle, sos, srd, std, szl, thb, tjs, tnd, top, try, ttd, twd,
     *     tzs, uah, ugx, uyu, uzs, vnd, vuv, wst, xaf, xcd, xcg, xof, xpf, yer, zar, zmw, usdc, btn, ghs, eek, lvl,
     *     svc, vef, ltl, sll, mro
     */
    public static function getCurrencyCodeBySymbol(string $symbol = null): string
    {
        $symbol = empty($symbol) ? lang('currency', '$') : $symbol;

        if (empty($symbol)) {
            return 'USD';
        }

        $currencyCodes = [
            '$' => 'USD',
            '€' => 'EUR',
            '£' => 'GBP',
            '¥' => 'JPY',
            '₹' => 'INR',
            '₩' => 'KRW',
            '₽' => 'RUB',
            '₺' => 'TRY',
            '₫' => 'VND',
            '₱' => 'PHP',
            '₦' => 'NGN',
            '₵' => 'GHS',
            '₣' => 'XOF',
            '₡' => 'CRC',
            '₭' => 'LAK',
            '₮' => 'MNT',
            '₲' => 'PYG',
            '₴' => 'UAH',
            '₸' => 'KZT',
        ];

        // Return the currency code based on the symbol
        return $currencyCodes[$symbol] ?? 'USD';
    }
}
