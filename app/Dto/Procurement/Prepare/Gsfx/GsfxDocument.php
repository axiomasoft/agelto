<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Нормализованная структурная модель контейнера ГрандСметы — вход детерминированного
 * gold-парсера. Разобрана из `Properties.txt` (паспорт) + `Data.xml` (разделы/позиции/
 * ресурсы/начисления/каталог индексов). `Data.xml` реального корпуса несёт СУЩЕСТВЕННО больше, чем
 * эта модель (см. `$unmapped`): каталог коэффициентов, группы похожих ресурсов, блок подписантов,
 * акт выполненных работ с собственными индексами/начислениями/подписями (`ImplemActs`) — вне
 * состава этой модели (она представляет СМЕТУ, не акта; акты — отдельный класс
 * корпуса, уже представленный `EST-XLS-KS2-001`/`EST-XLS-KS3-001`).
 *
 * @property list<GsfxChapter> $chapters
 * @property list<GsfxWorkTypeGroup> $work_type_groups
 * @property list<GsfxAddZatrGlava> $add_zatr_glavas
 * @property list<GsfxCoefficient> $coefficients
 * @property array<string, int> $unmapped
 */
final class GsfxDocument extends Data
{
    /**
     * @param  list<GsfxChapter>  $chapters
     * @param  list<GsfxWorkTypeGroup>  $work_type_groups
     * @param  list<GsfxAddZatrGlava>  $add_zatr_glavas
     * @param  list<GsfxCoefficient>  $coefficients  Документные `Document/Koefficients/K` —
     *                                               глобальный список без ссылки на позицию
     *                                               (`Options="InPos …"` — флаг применимости, не
     *                                               ID), по позициям не раскладывается.
     * @param  array<string, int>  $unmapped  Ключ `Тег` (непрочитанный элемент) или `Тег@Атрибут`
     *                                        (непрочитанный атрибут прочитанного узла) → сколько раз
     *                                        встретился («не смогли отобразить
     *                                        обязано быть видно»; видимость атрибутов)
     */
    public function __construct(
        public GsfxPassport $passport,
        public array $chapters = [],
        public array $work_type_groups = [],
        public array $add_zatr_glavas = [],
        public ?GsfxIndexCatalog $index_catalog = null,
        public array $coefficients = [],
        public array $unmapped = [],
    ) {}
}
