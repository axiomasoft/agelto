import { defineConfig } from 'vitepress';

export default defineConfig({
    base: '/admin/documentation/',
    cleanUrls: true,
    outDir: process.env.DOCUMENTATION_OUTPUT_DIR ?? '.vitepress/dist',
    title: 'Agelto',
    description: 'Внутренняя документация Agelto',
});
