import { defineConfig } from 'vitepress';
import { withMermaid } from 'vitepress-plugin-mermaid';

export default withMermaid(defineConfig({
    base: '/documentation/',
    cleanUrls: true,
    outDir: process.env.DOCUMENTATION_OUTPUT_DIR ?? '.vitepress/dist',
    rewrites: {
        '00_Ideas/_index.md': '00_Ideas/index.md',
        '01_Business/_index.md': '01_Business/index.md',
        '02_Workflow/_index.md': '02_Workflow/index.md',
        '03_Dev/_index.md': '03_Dev/index.md',
        '03_Dev/runbooks/_index.md': '03_Dev/runbooks/index.md',
        '04_Operators/_index.md': '04_Operators/index.md',
        '04_Operators/production/_index.md': '04_Operators/production/index.md',
    },
    title: 'Agelto',
    description: 'Рабочая документация Agelto: процессы, эксплуатация и разработка',
    lang: 'ru-RU',
    themeConfig: {
        siteTitle: 'Agelto',
        nav: [
            { text: 'Главная', link: '/', activeMatch: '^/$' },
            { text: 'Продукт', link: '/01_Business/', activeMatch: '^/01_Business/' },
            { text: 'Бизнес-процессы', link: '/02_Workflow/', activeMatch: '^/02_Workflow/' },
            { text: 'Эксплуатация', link: '/04_Operators/', activeMatch: '^/04_Operators/' },
            { text: 'Разработка', link: '/03_Dev/', activeMatch: '^/03_Dev/' },
        ],
        sidebar: {
            '/01_Business/': [
                {
                    text: 'Продукт',
                    items: [
                        { text: 'Обзор', link: '/01_Business/' },
                        { text: 'Продукт и ценность', link: '/01_Business/overview' },
                        { text: 'Дорожная карта', link: '/01_Business/roadmap' },
                        { text: 'Риски', link: '/01_Business/risk-register' },
                    ],
                },
            ],
            '/02_Workflow/': [
                {
                    text: 'Бизнес-процессы',
                    items: [
                        { text: 'Карта процессов', link: '/02_Workflow/' },
                        { text: 'Сбор и поиск закупок', link: '/02_Workflow/crawling-and-discovery' },
                        { text: 'Документы и сметы', link: '/02_Workflow/document-and-estimate-pipeline' },
                        { text: 'Промты и качество', link: '/02_Workflow/prompts-and-quality' },
                        { text: 'Глоссарий', link: '/02_Workflow/glossary' },
                    ],
                },
            ],
            '/04_Operators/': [
                {
                    text: 'Начало работы',
                    items: [
                        { text: 'Для оператора', link: '/04_Operators/' },
                        { text: 'Статусы в админке', link: '/04_Operators/reference/statuses' },
                    ],
                },
                {
                    text: 'Сервер и релизы',
                    items: [
                        { text: '1. Новый Ubuntu-сервер: полностью', link: '/04_Operators/production/server-setup' },
                        { text: 'Полное руководство по production', link: '/04_Operators/production/deployment' },
                        { text: 'Справочник production-команд', link: '/04_Operators/production/commands' },
                        { text: 'Восстановление и откат', link: '/04_Operators/production/rollback-restore' },
                        { text: 'Production troubleshooting', link: '/04_Operators/production/troubleshooting' },
                    ],
                },
                {
                    text: 'Работа с результатами',
                    collapsed: true,
                    items: [
                        { text: 'Проверить AI-анализ', link: '/04_Operators/how-to/check-ai-analysis' },
                        { text: 'Ревью сметы', link: '/04_Operators/how-to/review-estimate' },
                        { text: 'Перезапустить обработку', link: '/04_Operators/how-to/restart-failed-run' },
                        { text: 'Необрабатываемый документ', link: '/04_Operators/how-to/handle-unprocessable-document' },
                    ],
                },
            ],
            '/03_Dev/': [
                {
                    text: 'Начало разработки',
                    items: [
                        { text: 'Раздел для разработчиков', link: '/03_Dev/' },
                        { text: 'Локальный запуск', link: '/03_Dev/getting-started' },
                        { text: 'Архитектура', link: '/03_Dev/architecture' },
                        { text: 'Команды разработки', link: '/03_Dev/commands' },
                    ],
                },
                {
                    text: 'Система и AI',
                    items: [
                        { text: 'Краулинг', link: '/03_Dev/crawling-pipeline' },
                        { text: 'Документы', link: '/03_Dev/document-pipeline' },
                        { text: 'Нейросети и маршруты моделей', link: '/03_Dev/llm-providers' },
                        { text: 'Извлечение смет', link: '/03_Dev/ai/estimate-extraction' },
                        { text: 'Промты', link: '/03_Dev/ai/prompts' },
                    ],
                },
                {
                    text: 'Инфраструктура',
                    collapsed: true,
                    items: [
                        { text: 'Docker: обзор', link: '/03_Dev/docker/' },
                        { text: 'Production-образ и runtime', link: '/03_Dev/docker/production' },
                        { text: 'Модель релизов', link: '/03_Dev/process/deploy' },
                        { text: 'Тестирование', link: '/03_Dev/process/testing' },
                    ],
                },
            ],
        },
        search: {
            provider: 'local',
        },
    },
}));
