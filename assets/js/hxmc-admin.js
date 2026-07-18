/**
 * HXMC admin — Alpine component.
 * Loaded BEFORE Alpine (Alpine depends on this handle), both defer.
 */
document.addEventListener('alpine:init', function () {
	Alpine.data('hxmcApp', function () {
		return {
			items: [],
			page: 1,
			totalPages: 1,
			total: 0,
			filter: '',
			search: '',
			quality: (window.hxmcData && hxmcData.quality) || 82,
			loading: false,
			busy: false,
			scanning: false,
			scanDone: 0,
			scanTotal: 0,
			renameId: 0,
			replaceTarget: null,
			renameSlug: '',
			error: '',

			init: function () {
				this.load(1);
				var self = this;
				this.$watch('quality', function (q) {
					self.post('hxmc_quality', { quality: q }).catch(function () {});
				});
			},

			get pageLabel() {
				return this.page + ' / ' + this.totalPages + ' (' + this.total + ')';
			},
			get scanProgressLabel() {
				return this.scanDone + ' / ' + this.scanTotal + '…';
			},

			post: function (action, data) {
				var body = new URLSearchParams();
				body.set('action', action);
				body.set('nonce', hxmcData.nonce);
				Object.keys(data || {}).forEach(function (k) {
					var v = data[k];
					if (Array.isArray(v)) {
						v.forEach(function (x) { body.append(k + '[]', x); });
					} else {
						body.set(k, v);
					}
				});
				return fetch(hxmcData.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				}).then(function (r) { return r.json(); }).then(function (json) {
					if (!json || !json.success) {
						throw new Error((json && json.data && json.data.message) || 'Request failed');
					}
					return json.data;
				});
			},

			showError: function (msg) {
				this.error = msg;
				var box = this.$refs.errorBox;
				if (box && box.scrollIntoView) {
					box.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			},

			load: function (page) {
				var self = this;
				this.loading = true;
				this.error = '';
				this.post('hxmc_list', { page: page, filter: this.filter, hxmc_s: this.search })
					.then(function (d) {
						self.items = d.items;
						self.page = d.page;
						self.totalPages = d.total_pages;
						self.total = d.total;
					})
					.catch(function (e) { self.showError(e.message); })
					.finally(function () { self.loading = false; });
			},

			replaceItem: function (item) {
				var idx = this.items.findIndex(function (x) { return x.id === item.id; });
				if (idx >= 0) {
					this.items.splice(idx, 1, item);
				}
			},

			scanOne: function (item) {
				var self = this;
				this.busy = true;
				this.post('hxmc_scan', { ids: [item.id] })
					.then(function (d) { if (d.items[item.id]) { self.replaceItem(d.items[item.id]); } })
					.catch(function (e) { self.showError(e.message); })
					.finally(function () { self.busy = false; });
			},

			scanAll: function () {
				var self = this;
				this.scanning = true;
				this.error = '';
				this.post('hxmc_scan_ids', {})
					.then(function (d) {
						var ids = d.ids;
						self.scanTotal = ids.length;
						self.scanDone = 0;
						var chunkSize = 10;
						var next = function (offset) {
							if (offset >= ids.length) {
								self.scanning = false;
								self.load(self.page);
								return;
							}
							var chunk = ids.slice(offset, offset + chunkSize);
							self.post('hxmc_scan', { ids: chunk })
								.then(function (dd) {
									Object.keys(dd.items).forEach(function (k) {
										self.replaceItem(dd.items[k]);
									});
									self.scanDone = Math.min(ids.length, offset + chunkSize);
									next(offset + chunkSize);
								})
								.catch(function (e) {
									self.scanning = false;
									self.showError(e.message);
								});
						};
						next(0);
					})
					.catch(function (e) {
						self.scanning = false;
						self.showError(e.message);
					});
			},

			openRename: function (item) {
				this.renameId = item.id;
				this.renameSlug = 'img-' + item.id;
			},

			doRename: function (item) {
				if (!window.confirm(hxmcData.i18n.confirmRename)) {
					return;
				}
				var self = this;
				this.busy = true;
				this.error = '';
				this.post('hxmc_rename', { id: item.id, slug: this.renameSlug })
					.then(function (d) {
						self.replaceItem(d.item);
						self.renameId = 0;
					})
					.catch(function (e) { self.showError(e.message); })
					.finally(function () { self.busy = false; });
			},

			pickReplace: function (item) {
				this.replaceTarget = item;
				this.$refs.replaceFile.value = '';
				this.$refs.replaceFile.click();
			},

			doReplace: function () {
				var item = this.replaceTarget;
				var input = this.$refs.replaceFile;
				if (!item || !input.files || !input.files.length) {
					return;
				}
				if (!window.confirm(hxmcData.i18n.confirmReplace)) {
					return;
				}
				var self = this;
				this.busy = true;
				this.error = '';
				var fd = new FormData();
				fd.append('action', 'hxmc_replace');
				fd.append('nonce', hxmcData.nonce);
				fd.append('id', item.id);
				fd.append('file', input.files[0]);
				fetch(hxmcData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (!json || !json.success) {
							throw new Error((json && json.data && json.data.message) || 'Request failed');
						}
						self.replaceItem(json.data.item);
					})
					.catch(function (e) { self.showError(e.message); })
					.finally(function () { self.busy = false; self.replaceTarget = null; });
			},

			doCompress: function (item) {
				if (!window.confirm(hxmcData.i18n.confirmCompress)) {
					return;
				}
				var self = this;
				this.busy = true;
				this.error = '';
				this.post('hxmc_compress', { id: item.id, quality: this.quality })
					.then(function (d) { self.replaceItem(d.item); })
					.catch(function (e) { self.showError(e.message); })
					.finally(function () { self.busy = false; });
			},

			doConvert: function (item) {
				if (!window.confirm(hxmcData.i18n.confirmConvert)) {
					return;
				}
				var self = this;
				this.busy = true;
				this.error = '';
				this.post('hxmc_convert', { id: item.id, quality: this.quality })
					.then(function (d) { self.replaceItem(d.item); })
					.catch(function (e) { self.showError(e.message); })
					.finally(function () { self.busy = false; });
			}
		};
	});
});
