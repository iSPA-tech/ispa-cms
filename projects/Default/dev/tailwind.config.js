/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

const templatePaths = process.env.VITE_TEMPLATE_FOLDER === 'Default'
    ? ['./resources/views/welcome.blade.php', './projects/Default/views/**/*.php']
    : [`./projects/${process.env.VITE_TEMPLATE_FOLDER}/views/**/*.php`]

/** @type {import('tailwindcss').Config} */
module.exports = {
	content: [
		...templatePaths,
		'./resources/views/components/**/*.php',
		'./resources/views/components/**/*.php',
		'./resources/views/vendor/**/*.php',
		'./resources/views/mail/**/*.php',
		'./resources/views/errors/**/*.php',
		'./resources/js/**/*.{js,vue,ts,tsx}',
	],
	darkMode: 'class',
	theme: {
		extend: {
			container: {
				center: true,
				padding: {
					DEFAULT: '1rem',
					md: '0px',
				},
			},
			colors: {
				secondary: {
					50: '#fcf1ed',
					100: '#f7d4ca',
					200: '#f1b8a7',
					300: '#ec9c83',
					400: '#e67f60',
					500: '#df5930',
					600: '#d44b21',
					700: '#b03e1c',
					800: '#8d3216',
					900: '#581f0e',
					950: '#230c06'
				},
				main: {
					50: '#edf5fd',
					100: '#b7d7f6',
					200: '#93c3f1',
					300: '#4a9ce8',
					400: '#2688e3',
					500: '#1a73c7',
					600: '#155ea2',
					700: '#10497e',
					800: '#0c345a',
					900: '#092a48',
					950: '#051524'
				},
			},
			zIndex: {
				'90': '90',
				'100': '100',
				'999': '999',
				'9999': '9999',
			}
		}
	},
	plugins: [
		require('@tailwindcss/typography'),
		require('@tailwindcss/forms'),
		require('@tailwindcss/aspect-ratio'),
		require('@tailwindcss/container-queries')
	]
}
