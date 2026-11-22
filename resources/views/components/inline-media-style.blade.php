@once
    <style>
        .content-media {
            box-sizing: border-box;
            position: relative;
            width: min(100%, 360px);
            margin: 1rem 0;
        }

        .content-media img {
            display: block;
            width: 100%;
            max-width: 100%;
            height: auto;
            border-radius: 0.375rem;
        }

        .content-media figcaption {
            margin-top: 0.375rem;
            color: #64748b;
            font-size: 0.875rem;
            font-style: italic;
            line-height: 1.5;
            text-align: center;
        }

        .content-media--left {
            float: left;
            max-width: min(42%, 360px);
            margin: 0.125rem 1.25rem 0.75rem 0;
        }

        .content-media--right {
            float: right;
            max-width: min(42%, 360px);
            margin: 0.125rem 0 0.75rem 1.25rem;
        }

        .content-media--center {
            clear: both;
            max-width: min(100%, 460px);
            margin-right: auto;
            margin-left: auto;
        }

        .content-media--full {
            float: none;
            clear: both;
            width: 100%;
            max-width: 100%;
        }

        .content-media--full img {
            width: 100%;
        }

        .content-media--small {
            width: min(100%, 240px);
        }

        .content-media--medium {
            width: min(100%, 360px);
        }

        .content-media--large {
            width: min(100%, 560px);
        }

        .content-media--left.content-media--small,
        .content-media--right.content-media--small {
            width: min(32%, 240px);
        }

        .content-media--left.content-media--medium,
        .content-media--right.content-media--medium {
            width: min(38%, 360px);
        }

        .content-media--left.content-media--large,
        .content-media--right.content-media--large {
            width: min(46%, 560px);
        }

        .content-media--custom-width,
        .content-media--left.content-media--custom-width,
        .content-media--right.content-media--custom-width {
            width: min(100%, var(--content-media-width));
        }

        .content-media-clear {
            clear: both;
        }

        .content-math {
            overflow-x: auto;
            overflow-y: hidden;
            margin: 1rem 0;
        }

        .content-math__error {
            font-family: monospace;
            color: #b91c1c;
        }

        @media (max-width: 640px) {
            .content-media--left,
            .content-media--right {
                float: none;
                width: 100%;
                max-width: 100%;
                margin: 1rem 0;
            }
        }
    </style>
@endonce
