import { defineConfig } from 'vitepress'

export default defineConfig({
  lang: 'en-US',
  title: 'php-fast-extensions',
  description: 'High-performance PHP extensions written in Rust with ext-php-rs',
  cleanUrls: true,

  themeConfig: {
    nav: [
      { text: 'Home', link: '/' },
      { text: 'CsvStreamer', link: '/csv-streamer/' },
      { text: 'XlsxStreamer', link: '/excel-streamer/' },
      { text: 'JsonStreamer', link: '/json-streamer/' },
      { text: 'XmlStreamer', link: '/xml-streamer/' },
      { text: 'GitHub', link: 'https://github.com/sonyarianto/php-fast-extensions' },
    ],

    sidebar: {
      '/csv-streamer/': [
        {
          text: 'CsvStreamer',
          items: [
            { text: 'Overview', link: '/csv-streamer/' },
            { text: 'Installation', link: '/csv-streamer/installation' },
            { text: 'Usage', link: '/csv-streamer/usage' },
            { text: 'API Reference', link: '/csv-streamer/api' },
            { text: 'Performance', link: '/csv-streamer/performance' },
          ],
        },
      ],
      '/excel-streamer/': [
        {
          text: 'XlsxStreamer',
          items: [
            { text: 'Overview', link: '/excel-streamer/' },
            { text: 'Installation', link: '/excel-streamer/installation' },
            { text: 'Usage', link: '/excel-streamer/usage' },
            { text: 'API Reference', link: '/excel-streamer/api' },
            { text: 'Performance', link: '/excel-streamer/performance' },
          ],
        },
      ],
      '/json-streamer/': [
        {
          text: 'JsonStreamer',
          items: [
            { text: 'Overview', link: '/json-streamer/' },
            { text: 'Installation', link: '/json-streamer/installation' },
            { text: 'Usage', link: '/json-streamer/usage' },
            { text: 'API Reference', link: '/json-streamer/api' },
            { text: 'Performance', link: '/json-streamer/performance' },
          ],
        },
      ],
      '/xml-streamer/': [
        {
          text: 'XmlStreamer',
          items: [
            { text: 'Overview', link: '/xml-streamer/' },
            { text: 'Installation', link: '/xml-streamer/installation' },
            { text: 'Usage', link: '/xml-streamer/usage' },
            { text: 'API Reference', link: '/xml-streamer/api' },
            { text: 'Performance', link: '/xml-streamer/performance' },
          ],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/sonyarianto/php-fast-extensions' },
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 Sony AK',
    },
  },
})
