<?php
/**
 * Страница «Заявки».
 *
 * @package SF_Form_Sender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SF_FS_Submissions_Page {

	/**
	 * Вывод страницы: список или одна заявка.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap sf-fs">
			<div class="sf-fs-head">
				<h1><?php esc_html_e( 'Заявки', 'sf-form-sender' ); ?></h1>
				<?php SF_FS_Admin::language_switcher( SF_FS_Admin::PAGE_SUBMISSIONS ); ?>
			</div>

			<?php
			self::flash();

			if ( ! SF_FS_Settings::on( 'save_to_db' ) ) {
				self::storage_off_notice();
			}

			if ( ! SF_FS_Db::tables_ready() ) {
				SF_FS_Db::install();
			}

			if ( $view > 0 ) {
				self::render_one( $view );
			} else {
				self::render_list();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Сообщения после действий.
	 *
	 * @return void
	 */
	private static function flash() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['deleted'] ) ) {
			$count = (int) $_GET['deleted'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d — сколько заявок удалено. */
						_n( 'Удалена %d заявка.', 'Удалено заявок: %d.', $count, 'sf-form-sender' ),
						$count
					)
				)
			);
		}
		if ( isset( $_GET['saved'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Настройки таблицы сохранены.', 'sf-form-sender' )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Предупреждение о выключенном сохранении заявок.
	 *
	 * @return void
	 */
	private static function storage_off_notice() {
		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'Сохранение заявок в базу данных выключено — новые заявки в этот список не попадут.', 'sf-form-sender' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SF_FS_Admin::PAGE . '&tab=main' ) ); ?>">
					<?php esc_html_e( 'Включить в настройках', 'sf-form-sender' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Список                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Таблица заявок.
	 *
	 * @return void
	 */
	private static function render_list() {
		$per_page = (int) get_option( 'sf_fs_per_page', 20 );
		$per_page = max( 5, min( 500, $per_page ) );

		$total = SF_FS_Db::count();
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		$paged = isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$paged = max( 1, min( $pages, $paged ) );

		$rows    = SF_FS_Db::submissions( $per_page, $paged );
		$columns = SF_FS_Columns::visible();

		// Наименьшая ширина таблицы — сумма колонок с колонкой отметок.
		// Ниже неё таблица не сжимается, а прокручивается.
		$least = 32;
		foreach ( $columns as $column ) {
			$least += (int) $column['width'];
		}

		self::render_settings_panel( $per_page );
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sf-fs-list-form">
			<?php wp_nonce_field( 'sf_fs_submissions' ); ?>
			<input type="hidden" name="action" value="sf_fs_submissions">
			<input type="hidden" name="what" value="delete" id="sf-fs-what">
			<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>">

			<div class="tablenav top">
				<div class="alignleft actions">
					<button type="submit" class="button" data-sf-bulk
						data-sf-confirm="<?php esc_attr_e( 'Удалить выбранные заявки?', 'sf-form-sender' ); ?>">
						<?php esc_html_e( 'Удалить выбранные', 'sf-form-sender' ); ?>
					</button>
					<button type="submit" class="button sf-fs-danger" data-sf-all
						data-sf-confirm="<?php esc_attr_e( 'Удалить все заявки без возможности восстановления?', 'sf-form-sender' ); ?>">
						<?php esc_html_e( 'Удалить все', 'sf-form-sender' ); ?>
					</button>
				</div>
				<?php self::pagination( $paged, $pages, $total ); ?>
			</div>

			<?php
			/*
			 * Колонок бывает много: поля приходят из форм, и вширь таблица
			 * растёт быстрее экрана. Поэтому таблица прокручивается вбок
			 * внутри своей рамки, а не растягивает страницу, и по краю, за
			 * который прокрутка ещё не дошла, лежит затемнение — иначе
			 * спрятанные колонки ничем себя не выдают.
			 */
			?>
			<div class="sf-fs-scroll" id="sf-fs-scroll">
				<div class="sf-fs-scroll__view">
					<?php
					/*
					 * Последняя колонка — пустая и без заданной ширины: при
					 * фиксированной раскладке весь остаток ширины достаётся
					 * ей одной, и остальные колонки стоят ровно там, куда их
					 * поставили мышью, а не расползаются по свободному месту.
					 */
					?>
					<table class="wp-list-table widefat striped sf-fs-list" id="sf-fs-table"
						style="min-width:<?php echo esc_attr( (string) $least ); ?>px">
						<colgroup>
							<col class="sf-fs-list__check">
							<?php foreach ( $columns as $column ) : ?>
								<col data-key="<?php echo esc_attr( $column['key'] ); ?>"
									style="width:<?php echo esc_attr( (string) (int) $column['width'] ); ?>px">
							<?php endforeach; ?>
							<col class="sf-fs-list__rest">
						</colgroup>
						<thead>
							<tr>
								<td class="check-column"><input type="checkbox" id="sf-fs-check-all"></td>
								<?php foreach ( $columns as $column ) : ?>
									<th scope="col" class="sf-fs-col sf-fs-col--<?php echo esc_attr( trim( $column['key'], '_' ) ); ?>"
										data-key="<?php echo esc_attr( $column['key'] ); ?>"
										data-default-width="<?php echo esc_attr( (string) (int) $column['default_width'] ); ?>">
										<span class="sf-fs-col__label"><?php echo esc_html( $column['label'] ); ?></span>
										<span class="sf-fs-col__grip" data-sf-resize
											title="<?php esc_attr_e( 'Потяните, чтобы изменить ширину колонки. Двойной щелчок вернёт ширину по умолчанию.', 'sf-form-sender' ); ?>"></span>
									</th>
								<?php endforeach; ?>
								<td class="sf-fs-list__rest"></td>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $rows ) : ?>
							<tr>
								<td colspan="<?php echo esc_attr( (string) ( count( $columns ) + 2 ) ); ?>">
									<?php esc_html_e( 'Заявок пока нет.', 'sf-form-sender' ); ?>
								</td>
							</tr>
						<?php endif; ?>

						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
								</th>
								<?php foreach ( $columns as $column ) : ?>
									<td><?php echo self::cell( $column['key'], $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<?php endforeach; ?>
								<td class="sf-fs-list__rest"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<span class="sf-fs-scroll__fade sf-fs-scroll__fade--left" aria-hidden="true"></span>
				<span class="sf-fs-scroll__fade sf-fs-scroll__fade--right" aria-hidden="true"></span>
			</div>

			<div class="tablenav bottom">
				<?php self::pagination( $paged, $pages, $total ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Содержимое ячейки.
	 *
	 * @param string              $key Ключ колонки.
	 * @param array<string,mixed> $row Заявка.
	 * @return string Готовый HTML.
	 */
	private static function cell( $key, $row ) {
		$id   = (int) $row['id'];
		$link = admin_url( 'admin.php?page=' . SF_FS_Admin::PAGE_SUBMISSIONS . '&view=' . $id );

		switch ( $key ) {
			case '_id':
				return '<a href="' . esc_url( $link ) . '"><strong>' . esc_html( (string) $id ) . '</strong></a>';

			case '_date':
				return esc_html( self::format_date( (string) $row['created_at'] ) );

			case '_ip':
				return esc_html( (string) $row['ip'] );

			case '_files':
				return esc_html( (string) (int) $row['files_count'] );

			case '_url':
				return '<a href="' . esc_url( (string) $row['page_url'] ) . '" target="_blank" rel="noopener">'
					. esc_html( self::short_url( (string) $row['page_url'] ) ) . '</a>';

			case '_form':
				return esc_html( (string) $row['form_id'] );

			case '_view':
				return '<a class="button button-small" href="' . esc_url( $link ) . '">'
					. esc_html__( 'Посмотреть', 'sf-form-sender' ) . '</a>';

			case '_delete':
				return '<button type="submit" class="button-link sf-fs-del" data-sf-one="' . esc_attr( (string) $id ) . '"'
					. ' data-sf-confirm="' . esc_attr__( 'Удалить заявку?', 'sf-form-sender' ) . '">'
					. esc_html__( 'Удалить', 'sf-form-sender' ) . '</button>';

			default:
				$value = isset( $row['data'][ $key ] ) ? (string) $row['data'][ $key ] : '';
				return esc_html( mb_substr( $value, 0, 200 ) );
		}
	}

	/**
	 * Дата в формате, заданном в настройках WordPress.
	 *
	 * @param string $mysql Дата из базы.
	 * @return string
	 */
	private static function format_date( $mysql ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$time   = strtotime( $mysql );
		return $time ? wp_date( $format, $time ) : $mysql;
	}

	/**
	 * Короткая запись адреса — для узкой колонки.
	 *
	 * @param string $url Адрес.
	 * @return string
	 */
	private static function short_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$short = ( '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' );
		return mb_strlen( $short ) > 60 ? mb_substr( $short, 0, 57 ) . '…' : $short;
	}

	/**
	 * Постраничная навигация.
	 *
	 * @param int $paged Текущая страница.
	 * @param int $pages Всего страниц.
	 * @param int $total Всего заявок.
	 * @return void
	 */
	private static function pagination( $paged, $pages, $total ) {
		?>
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					esc_html(
						/* translators: %s — количество заявок. */
						_n( '%s заявка', 'Заявок: %s', $total, 'sf-form-sender' )
					),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
			<?php
			if ( $pages > 1 ) {
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '‹',
							'next_text' => '›',
							'total'     => $pages,
							'current'   => $paged,
							'type'      => 'plain',
						)
					)
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Настройка колонок над таблицей.
	 *
	 * @param int $per_page Строк на странице.
	 * @return void
	 */
	private static function render_settings_panel( $per_page ) {
		$columns = SF_FS_Columns::all();
		?>
		<details class="sf-fs-columns" <?php echo isset( $_GET['saved'] ) ? 'open' : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>>
			<summary><?php esc_html_e( 'Настроить колонки таблицы', 'sf-form-sender' ); ?></summary>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sf_fs_save_columns' ); ?>
				<input type="hidden" name="action" value="sf_fs_save_columns">
				<input type="hidden" name="column_order" id="sf-fs-column-order" value="">

				<p class="description">
					<?php esc_html_e( 'Имена полей приходят из форм, поэтому названия колонок задаются здесь. Постоянные колонки убрать нельзя, но переставить можно вместе с остальными.', 'sf-form-sender' ); ?>
				</p>

				<ul class="sf-fs-columns__list" id="sf-fs-column-list">
					<?php foreach ( $columns as $column ) : ?>
						<li data-key="<?php echo esc_attr( $column['key'] ); ?>">
							<span class="sf-fs-columns__move">
								<button type="button" class="button-link" data-sf-move="up" aria-label="<?php esc_attr_e( 'Выше', 'sf-form-sender' ); ?>">▲</button>
								<button type="button" class="button-link" data-sf-move="down" aria-label="<?php esc_attr_e( 'Ниже', 'sf-form-sender' ); ?>">▼</button>
							</span>

							<label class="sf-fs-columns__show">
								<input type="checkbox" name="column_visible[]" value="<?php echo esc_attr( $column['key'] ); ?>"
									<?php checked( $column['visible'] ); ?>
									<?php disabled( $column['fixed'] ); ?>>
								<?php if ( $column['fixed'] ) : ?>
									<input type="hidden" name="column_visible[]" value="<?php echo esc_attr( $column['key'] ); ?>">
								<?php endif; ?>
							</label>

							<code class="sf-fs-columns__key"><?php echo esc_html( $column['key'] ); ?></code>

							<?php if ( $column['field_id'] ) : ?>
								<input type="text" class="sf-fs-columns__label"
									name="field_label[<?php echo esc_attr( (string) $column['field_id'] ); ?>]"
									value="<?php echo esc_attr( $column['label'] ); ?>">
							<?php else : ?>
								<span class="sf-fs-columns__label sf-fs-columns__label--fixed"><?php echo esc_html( $column['label'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>

				<p class="sf-fs-columns__perpage">
					<label for="sf-fs-per-page"><?php esc_html_e( 'Строк на странице', 'sf-form-sender' ); ?></label>
					<input type="number" id="sf-fs-per-page" name="per_page" min="5" max="500"
						value="<?php echo esc_attr( (string) $per_page ); ?>" class="small-text">
				</p>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить', 'sf-form-sender' ); ?></button>
				</p>
			</form>
		</details>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Одна заявка                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Просмотр заявки.
	 *
	 * @param int $id Номер заявки.
	 * @return void
	 */
	private static function render_one( $id ) {
		$row = SF_FS_Db::submission( $id );
		$back = admin_url( 'admin.php?page=' . SF_FS_Admin::PAGE_SUBMISSIONS );

		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Заявка не найдена — возможно, её уже удалили.', 'sf-form-sender' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'К списку заявок', 'sf-form-sender' ) . '</a></p>';
			return;
		}

		$fields = SF_FS_Db::fields();
		?>
		<p class="sf-fs-back">
			<a class="button" href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'К списку заявок', 'sf-form-sender' ); ?></a>
		</p>

		<h2><?php printf( esc_html__( 'Заявка №%d', 'sf-form-sender' ), (int) $row['id'] ); ?></h2>

		<table class="widefat striped sf-fs-one">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Дата и время', 'sf-form-sender' ); ?></th>
					<td><?php echo esc_html( self::format_date( (string) $row['created_at'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'IP отправителя', 'sf-form-sender' ); ?></th>
					<td><?php echo esc_html( (string) $row['ip'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Страница', 'sf-form-sender' ); ?></th>
					<td><a href="<?php echo esc_url( (string) $row['page_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $row['page_url'] ); ?></a></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Форма', 'sf-form-sender' ); ?></th>
					<td><?php echo esc_html( (string) $row['form_id'] ); ?></td>
				</tr>

				<?php foreach ( $row['data'] as $key => $value ) : ?>
					<tr>
						<th>
							<?php echo esc_html( isset( $fields[ $key ] ) && '' !== $fields[ $key ]['label'] ? $fields[ $key ]['label'] : $key ); ?>
							<code class="sf-fs-one__key"><?php echo esc_html( $key ); ?></code>
						</th>
						<td><?php echo nl2br( esc_html( (string) $value ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $row['files'] ) : ?>
			<h3><?php esc_html_e( 'Прикреплённые файлы', 'sf-form-sender' ); ?></h3>
			<ul class="sf-fs-files">
				<?php foreach ( $row['files'] as $file ) : ?>
					<li>
						<a href="<?php echo esc_url( SF_FS_Uploads::url( (string) $file['rel_path'] ) ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( (string) $file['orig_name'] ); ?>
						</a>
						<span class="sf-fs-files__size"><?php echo esc_html( size_format( (int) $file['size'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sf-fs-one__delete">
			<?php wp_nonce_field( 'sf_fs_submissions' ); ?>
			<input type="hidden" name="action" value="sf_fs_submissions">
			<input type="hidden" name="what" value="delete">
			<input type="hidden" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
			<button type="submit" class="button sf-fs-danger"
				data-sf-confirm="<?php esc_attr_e( 'Удалить заявку?', 'sf-form-sender' ); ?>">
				<?php esc_html_e( 'Удалить заявку', 'sf-form-sender' ); ?>
			</button>
		</form>
		<?php
	}
}
