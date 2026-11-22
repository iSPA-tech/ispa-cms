<?php

namespace App\Casts;

use JsonException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Encrypts Setting values while allowing non-string inputs (arrays, booleans, numerics).
 */
class EncryptedSettingValue implements CastsAttributes
{
	private const BOOLEAN_TYPES = ['boolean', 'switch'];
	private const NUMERIC_TYPES = ['number', 'integer', 'float', 'double', 'decimal'];
	private const JSON_TYPES = [
		'image',
		'images',
		'file',
		'files',
		'gallery',
		'upload',
		'uploads',
		'media',
		'json',
		'array',
		'list',
	];
	private const JSON_SUFFIXES = ['_json', '_list', '_array'];

	/**
	 * @throws JsonException
	 */
	public function set($model, string $key, $value, array $attributes): mixed
	{
		if ($value === null || $value === '') {
			return $value;
		}

		if (is_bool($value)) {
			$value = $value ? 'true' : 'false';
		} else if (is_array($value) || is_object($value)) {
			$value = json_encode($value, JSON_THROW_ON_ERROR);
		} else if (!is_string($value)) {
			$value = (string)$value;
		}

		if ($this->shouldEncrypt($attributes)) {
			return Crypt::encryptString($value);
		}

		return $value;
	}

	/**
	 * Determine whether this setting's value should be encrypted at rest.
	 *
	 * Encryption is applied when any of these conditions is true:
	 *  - type === 'password'
	 *  - input_setting.isPublic === false
	 *  - input_setting.isEnv === true
	 */
	private function shouldEncrypt(array $attributes): bool
	{
		$type = isset($attributes['type']) ? strtolower((string)$attributes['type']) : null;

		if ($type === 'password') {
			return true;
		}

		$inputSetting = $attributes['input_setting'] ?? null;
		$data = $inputSetting;

		if (is_string($inputSetting) && $inputSetting !== '') {
			$decoded = json_decode($inputSetting, true);
			if (is_array($decoded)) {
				$data = $decoded;
			}
		}

		if (!is_array($data)) {
			return false;
		}

		$isEnv = (bool)Arr::get($data, 'isEnv', Arr::get($data, 'is_env', false));
		$isPublic = (bool)Arr::get($data, 'isPublic', Arr::get($data, 'is_public', true));

		return $isEnv || !$isPublic;
	}

	/**
	 * @throws DecryptException
	 */
	public function get($model, string $key, $value, array $attributes): mixed
	{
		if ($value === null || $value === '') {
			return $value;
		}

		try {
			$decrypted = Crypt::decryptString($value);
		} catch (DecryptException) {
			// Value may already be stored as plaintext (e.g. legacy rows), return as-is.
			$decrypted = $value;
		}

		$type = isset($attributes['type']) ? strtolower((string)$attributes['type']) : null;
		$inputSetting = $attributes['input_setting'] ?? null;

		if ($type !== null && $this->isBooleanType($type)) {
			$bool = $this->castBoolean($decrypted);

			if ($bool !== null) {
				return $bool;
			}
		}

		if ($type !== null && $this->isNumericType($type)) {
			$numeric = $this->castNumeric($decrypted);

			if ($numeric !== null) {
				return $numeric;
			}
		}

		if ($this->shouldDecodeToArray($type, $inputSetting, $decrypted)) {
			$decoded = $this->decodeJson($decrypted);

			if ($decoded !== null) {
				return $decoded;
			}
		}

		if (isset($attributes['setting_key']) && $this->shouldDecodeUsingSettingKey($attributes['setting_key'])) {
			$decoded = $this->decodeJson($decrypted);

			if ($decoded !== null) {
				return $decoded;
			}
		}

		return $decrypted;
	}

	private function isBooleanType(string $type): bool
	{
		return in_array($type, self::BOOLEAN_TYPES, true);
	}

	private function castBoolean(string $value): ?bool
	{
		$normalized = strtolower($value);

		if (in_array($normalized, ['true', 'false'], true)) {
			return $normalized === 'true';
		}

		if (is_numeric($value)) {
			return (bool)((int)$value);
		}

		return null;
	}

	private function isNumericType(string $type): bool
	{
		return in_array($type, self::NUMERIC_TYPES, true);
	}

	private function castNumeric(string $value): int|float|null
	{
		if (!is_numeric($value)) {
			return null;
		}

		return str_contains((string)$value, '.') ? (float)$value : (int)$value;
	}

	private function shouldDecodeToArray(?string $type, $inputSetting, string $value): bool
	{
		if ($type !== null) {
			if (in_array($type, self::JSON_TYPES, true)) {
				return true;
			}

			foreach (self::JSON_SUFFIXES as $suffix) {
				if (str_ends_with($type, $suffix)) {
					return true;
				}
			}
		}

		if ($this->inputSettingRequiresArray($inputSetting)) {
			return true;
		}

		return $this->looksLikeJson($value);
	}

	private function inputSettingRequiresArray($inputSetting): bool
	{
		if (!is_string($inputSetting) || $inputSetting === '') {
			return false;
		}

		try {
			$decoded = json_decode($inputSetting, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return false;
		}

		return is_array($decoded) && !empty($decoded['multiple']);
	}

	private function looksLikeJson(string $value): bool
	{
		$trimmed = ltrim($value);

		if ($trimmed === '') {
			return false;
		}

		$first = $trimmed[0];

		return $first === '[' || $first === '{';
	}

	private function decodeJson(string $value): mixed
	{
		try {
			return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}
	}

	private function shouldDecodeUsingSettingKey(string $settingKey): bool
	{
		$needle = strtolower($settingKey);

		$isLogo = str_contains($needle, 'logo');
		$isImage = str_contains($needle, 'image') || str_contains($needle, 'banner');
		$isUpload = str_contains($needle, 'upload') || str_contains($needle, 'attachment') || str_contains($needle, 'file');

		return $isLogo || $isImage || $isUpload;
	}
}
