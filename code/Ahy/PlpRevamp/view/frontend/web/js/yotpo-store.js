document.addEventListener('alpine:init', function() {
    if (Alpine.store('yotpoReviews')) return;
    Alpine.store('yotpoReviews', {
        data: {},
        fetch: async function(ids, baseUrl) {
            if (!ids || !ids.length) return;
            var self = this;
            var newIds = ids.filter(function(id) { return !(String(id) in self.data); });
            if (!newIds.length) return;
            newIds.forEach(function(id) { self.data[String(id)] = null; });
            try {
                var r = await fetch(baseUrl + '?ids[]=' + newIds.join('&ids[]='));
                if (r.ok) {
                    var json = await r.json();
                    self.data = Object.assign({}, self.data, json);
                }
            } catch(e) {}
        }
    });
});
