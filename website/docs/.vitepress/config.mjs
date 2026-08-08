import { defineConfig } from 'vitepress'

export default defineConfig({
  lang: 'en-US',
  title: 'php-fast-extensions',
  description: 'High-performance PHP extensions written in Rust with ext-php-rs',
  cleanUrls: true,

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Home', link: '/' },
      { text: 'Extensions', link: '/csv-streamer/' },
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
