<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace PJ\Default\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class SupplierCompanyInfo
 *
 * @package PJ\Default\Models
 *
 * @property string $company_name
 * @property string $company_full_address
 * @property string $company_ward
 * @property string $company_district
 * @property string $company_province
 * @property string $company_country
 * @property string $company_phone
 * @property string $company_fax
 * @property string $company_email
 * @property string $company_website
 * @property string $company_main_marketing
 * @property string $company_business
 * @property string $company_product_and_services
 * @property string $personal_name
 * @property string $personal_telephone
 * @property string $personal_mobile_phone
 * @property string $personal_email
 */
class SupplierCompanyInfo extends Model
{
    protected $table = 'SupplierCompanyInfo';

    protected $connection = 'crawler_mysql';

    protected $fillable = [
        'company_name',
        'company_full_address',
        'company_ward',
        'company_district',
        'company_province',
        'company_country',
        'company_phone',
        'company_fax',
        'company_email',
        'company_website',
        'company_main_marketing',
        'company_business',
        'company_product_and_services',
        'personal_name',
        'personal_telephone',
        'personal_mobile_phone',
        'personal_email',
    ];
}
