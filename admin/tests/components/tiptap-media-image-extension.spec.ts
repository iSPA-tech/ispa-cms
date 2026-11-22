import { describe, expect, it, vi } from 'vitest'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

vi.mock('@/components/modal-media-center.vue', () => ({
    default: {
        name: 'ModalMediaCenter',
        props: ['show'],
        template: '<div class="media-modal"></div>',
    },
}))

import {
    convertHtmlImagesToMediaFigures,
    createMediaImageAttributes,
    MediaImage,
} from '@/components/tiptap-media-image-extension'
import TipTapMediaImage from '@/components/tiptap-media-image.vue'

describe('tiptap media image attributes', () => {
    it('converts an upload record into constrained inline media attributes', () => {
        expect(
            createMediaImageAttributes(
                {
                    id: 42,
                    url: 'storage/posts/chapel.jpg',
                    name: 'Chapel original file name.jpg',
                },
                {
                    align: 'right',
                    size: 'large',
                    alt: 'Chapel of the Holy Cross',
                    caption: 'Sedona, Arizona',
                }
            )
        ).toEqual({
            src: '/storage/posts/chapel.jpg',
            uploadId: '42',
            align: 'right',
            size: 'large',
            width: '',
            alt: 'Chapel of the Holy Cross',
            caption: 'Sedona, Arizona',
        })
    })

    it('falls back to safe defaults for unsupported formatting values', () => {
        expect(
            createMediaImageAttributes(
                {
                    id: 7,
                    url: 'https://cdn.example.test/photo.webp',
                    name: 'Photo name',
                },
                {
                    align: 'floaty',
                    size: 'poster',
                }
            )
        ).toMatchObject({
            src: 'https://cdn.example.test/photo.webp',
            uploadId: '7',
            align: 'center',
            size: 'medium',
            alt: 'Photo name',
            caption: '',
        })
    })

    it('normalizes custom media width into a bounded display attribute', () => {
        expect(
            createMediaImageAttributes(
                {
                    id: 42,
                    url: 'storage/posts/chapel.jpg',
                },
                {
                    width: '1440px',
                }
            )
        ).toMatchObject({
            width: '900px',
        })

        expect(
            createMediaImageAttributes(
                {
                    id: 42,
                    url: 'storage/posts/chapel.jpg',
                },
                {
                    width: '4.5in',
                }
            )
        ).toMatchObject({
            width: '432px',
        })

        expect(
            createMediaImageAttributes(
                {
                    id: 42,
                    url: 'storage/posts/chapel.jpg',
                },
                {
                    width: 'calc(100% - 2rem)',
                }
            )
        ).toMatchObject({
            width: '',
        })
    })

    it('renders a persisted figure with upload tracking metadata', () => {
        const editor = new Editor({
            extensions: [StarterKit, MediaImage],
            content: '',
        })

        editor.commands.setMediaImage({
            src: '/storage/posts/chapel.jpg',
            uploadId: 42,
            align: 'left',
            size: 'small',
            alt: 'Chapel',
            caption: 'Sedona',
            width: '480px',
        })

        const html = editor.getHTML()
        editor.destroy()

        expect(html).toContain(
            'class="content-media content-media--left content-media--small content-media--custom-width"'
        )
        expect(html).toContain('data-upload-id="42"')
        expect(html).toContain('data-media-align="left"')
        expect(html).toContain('data-media-width="480px"')
        expect(html).toContain('--content-media-width: 480px')
        expect(html).toContain('<figcaption>Sedona</figcaption>')
    })

    it('converts pasted image html into controlled media figures', () => {
        const html = convertHtmlImagesToMediaFigures(`
            <p class="MsoNormal">
                <span>
                    <img src="/storage/posts/word-image.jpg" width="640" alt="Word image">
                </span>
            </p>
            <p><img src="file:///Users/me/Pictures/local.jpg"></p>
        `)

        expect(html).toContain('class="content-media content-media--center content-media--medium')
        expect(html).toContain('src="/storage/posts/word-image.jpg"')
        expect(html).toContain('alt="Word image"')
        expect(html).toContain('data-media-width="640px"')
        expect(html).not.toContain('file:///Users/me/Pictures/local.jpg')
    })

    it('replaces the current empty paragraph when inserting a floating image', () => {
        const editor = new Editor({
            extensions: [StarterKit, MediaImage],
            content: '<p>Intro</p><p></p><p>After</p>',
        })

        editor.commands.setTextSelection(8)
        expect(editor.state.selection.$from.parent.content.size).toBe(0)

        editor.commands.setMediaImage({
            src: '/storage/posts/chapel.jpg',
            uploadId: 42,
            align: 'right',
            size: 'medium',
        })

        const html = editor.getHTML()
        editor.destroy()

        expect(html).not.toContain('<p></p>')
        expect(html).toContain('<p>Intro</p><figure')
    })

    it('hides the editor popover while the media center modal is open', async () => {
        const wrapper = mount(TipTapMediaImage, {
            props: {
                editor: {
                    getAttributes: () => ({}),
                    chain: () => ({
                        focus: () => ({
                            deleteSelection: () => ({ run: () => true }),
                            updateMediaImage: () => ({ run: () => true }),
                            setMediaImage: () => ({ run: () => true }),
                        }),
                    }),
                },
                toolbar: { title: 'Inline image' },
                isActive: () => false,
            },
            global: {
                stubs: {
                    'a-popover': {
                        props: ['open'],
                        template: '<div><slot /><div><slot name="content" /></div></div>',
                    },
                    'a-form': { template: '<form><slot /></form>' },
                    'a-form-item': { template: '<div><slot /></div>' },
                    'a-input': true,
                    'a-radio-group': { template: '<div><slot /></div>' },
                    'a-radio-button': { template: '<button type="button"><slot /></button>' },
                    'a-button': {
                        template: '<button type="button" @click="$emit(\'click\', $event)"><slot /></button>',
                    },
                    'i-icon': true,
                    'modal-media-center': true,
                },
            },
        })

        const vm = wrapper.vm as unknown as {
            popoverState: boolean
            mediaShowState: boolean
        }
        vm.popoverState = true
        await nextTick()

        const selectButton = wrapper
            .findAll('button')
            .find((button) => button.text().includes('text.editor.media_image.select'))

        expect(selectButton).toBeTruthy()
        await selectButton?.trigger('click')
        await nextTick()

        expect(vm.popoverState).toBe(false)
        expect(vm.mediaShowState).toBe(true)
    })
})
