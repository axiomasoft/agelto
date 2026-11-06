import { defineConfig } from 'vitepress';

export default defineConfig({
    base: '/documentation/',
    cleanUrls: true,
    outDir: process.env.DOCUMENTATION_OUTPUT_DIR ?? '.vitepress/dist',
    rewrites: {
        'ideas/_index.md': 'ideas/index.md',
        'business/_index.md': 'business/index.md',
        'workflow/_index.md': 'workflow/index.md',
        'dev/_index.md': 'dev/index.md',
        'dev/runbooks/_index.md': 'dev/runbooks/index.md',
        'operators/_index.md': 'operators/index.md',
        'operators/production/_index.md': 'operators/production/index.md',
    },
    title: 'Agelto',
    description: 'Рабочая документация Agelto: процессы, эксплуатация и разработка',
    lang: 'ru-RU',
    themeConfig: {
        siteTitle: 'Agelto',
        nav: [
            { text: 'Главная', link: '/', activeMatch: '^/$' },
            { text: 'Продукт', link: '/business/', activeMatch: '^/business/' },
            { text: 'Бизнес-процессы', link: '/workflow/', activeMatch: '^/workflow/' },
            { text: 'Эксплуатация', link: '/operators/', activeMatch: '^/operators/' },
            { text: 'Разработка', link: '/dev/', activeMatch: '^/dev/' },
        ],
        sidebar: {
            '/business/': [
                {
                    text: 'Продукт',
                    items: [
                        { text: 'Обзор', link: '/business/' },
                        { text: 'Продукт и ценность', link: '/business/overview' },
                        { text: 'Дорожная карта', link: '/business/roadmap' },
                        { text: 'Риски', link: '/business/risk-register' },
                    ],
                },
            ],
            '/workflow/': [
                {
                    text: 'Бизнес-процессы',
                    items: [
                        { text: 'Карта процессов', link: '/workflow/' },
                        { text: 'Сбор и поиск закупок', link: '/workflow/crawling-and-discovery' },
                        { text: 'Документы и сметы', link: '/workflow/document-and-estimate-pipeline' },
                        { text: 'Промты и качество', link: '/workflow/prompts-and-quality' },
                        { text: 'Глоссарий', link: '/workflow/glossary' },
                    ],
                },
            ],
            '/operators/': [
                {
                    text: 'Начало работы',
                    items: [
                        { text: 'Для оператора', link: '/operators/' },
                        { text: 'Статусы в админке', link: '/operators/reference/statuses' },
                    ],
                },
                {
                    text: 'Сервер и релизы',
                    items: [
                        { text: '1. Новый Ubuntu-сервер: полностью', link: '/operators/production/server-setup' },
                        { text: 'Полное руководство по production', link: '/operators/production/deployment' },
                        { text: 'Справочник production-команд', link: '/operators/production/commands' },
                        { text: 'Восстановление и откат', link: '/operators/production/rollback-restore' },
                        { text: 'Production troubleshooting', link: '/operators/production/troubleshooting' },
                    ],
                },
                {
                    text: 'Работа с результатами',
                    collapsed: true,
                    items: [
                        { text: 'Проверить AI-анализ', link: '/operators/how-to/check-ai-analysis' },
                        { text: 'Ревью сметы', link: '/operators/how-to/review-estimate' },
                        { text: 'Перезапустить обработку', link: '/operators/how-to/restart-failed-run' },
                        { text: 'Необрабатываемый документ', link: '/operators/how-to/handle-unprocessable-document' },
                    ],
                },
            ],
            '/dev/': [
                {
                    text: 'Начало разработки',
                    items: [
                        { text: 'Раздел для разработчиков', link: '/dev/' },
                        { text: 'Локальный запуск', link: '/dev/getting-started' },
                        { text: 'Архитектура', link: '/dev/architecture' },
                        { text: 'Команды разработки', link: '/dev/commands' },
                    ],
                },
                {
                    text: 'Система и AI',
                    items: [
                        { text: 'Краулинг', link: '/dev/crawling-pipeline' },
                        { text: 'Документы', link: '/dev/document-pipeline' },
                        { text: 'Нейросети и маршруты моделей', link: '/dev/llm-providers' },
                        { text: 'Извлечение смет', link: '/dev/ai/estimate-extraction' },
                        { text: 'Промты', link: '/dev/ai/prompts' },
                    ],
                },
                {
                    text: 'Инфраструктура',
                    collapsed: true,
                    items: [
                        { text: 'Docker: обзор', link: '/dev/docker/' },
                        { text: 'Production-образ и runtime', link: '/dev/docker/production' },
                        { text: 'Модель релизов', link: '/dev/process/deploy' },
                        { text: 'Тестирование', link: '/dev/process/testing' },
                        { text: 'Аудит репозитория', link: '/dev/repository-audit' },
                    ],
                },
            ],
        },
    },
});
