import { defineConfig } from 'vitepress';

export default defineConfig({
    lang: 'ru-RU',
    title: 'Agelto Docs',
    description: 'Документация проекта Agelto',
    cleanUrls: true,
    themeConfig: {
        nav: [
            { text: 'Разработка', link: '/dev/' },
            { text: 'Server', link: '/server/setup' },
            { text: 'Workflow', link: '/workflow/' },
            { text: 'AI', link: '/ai/' },
        ],
        sidebar: {
            '/dev/': [
                {
                    text: 'Разработка',
                    items: [
                        { text: 'Обзор', link: '/dev/' },
                        { text: 'Запуск и разработка', link: '/dev/getting-started' },
                        { text: 'Архитектура', link: '/dev/architecture' },
                        { text: 'Прокси', link: '/dev/proxies' },
                        { text: 'Docker', link: '/dev/docker/' },
                        { text: 'Подготовка окружения', link: '/dev/docker/environment-preparation' },
                        { text: 'Docker: development', link: '/dev/docker/development' },
                        { text: 'Docker: production (локально)', link: '/dev/docker/production' },
                        { text: 'Docker: troubleshooting', link: '/dev/docker/troubleshooting' },
                    ],
                },
            ],
            '/server/': [
                {
                    text: 'Production сервер',
                    items: [
                        { text: 'Подготовка хоста', link: '/server/setup' },
                        { text: 'Docker Compose prod', link: '/server/docker-prod' },
                        { text: 'Процесс деплоя', link: '/server/deploy' },
                    ],
                },
            ],
            '/workflow/': [
                {
                    text: 'Workflow',
                    items: [
                        { text: 'Обзор', link: '/workflow/' },
                        { text: 'Парсинг смет (бизнес-процесс)', link: '/workflow/parsing-process' },
                    ],
                },
            ],
            '/ai/': [
                {
                    text: 'AI',
                    items: [
                        { text: 'Обзор', link: '/ai/' },
                        { text: 'Единый источник истины', link: '/ai/source-of-truth' },
                    ],
                },
            ],
        },
    },
});

