const request = function (method, route, data = {}) {
    const url = `${window.fluentCommentVars.rest.url}/${route}`;

    const headers = {
        'X-WP-Nonce': window.fluentCommentVars.rest.nonce
    };

    if (['PUT', 'PATCH', 'DELETE'].indexOf(method.toUpperCase()) !== -1) {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
    }

    data.query_timestamp = Date.now();

    const formData = new FormData();

    Object.keys(data).forEach(key => {
        formData.append(key, data[key]);
    });

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        for (let key in headers) {
            xhr.setRequestHeader(key, headers[key]);
        }
        xhr.responseType = 'json';

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(xhr.response);
            } else {
                reject({
                    status: xhr.status,
                    statusText: xhr.statusText,
                    response: xhr.response
                });
            }
        };
        xhr.onerror = function () {
            reject({
                status: xhr.status,
                statusText: xhr.statusText
            });
        };
        xhr.send(formData);
    });
};

export const rest = {
    get(route, data = {}) {
        return request('GET', route, data);
    },
    post(route, data = {}) {
        return request('POST', route, data);
    },
    delete(route, data = {}) {
        return request('DELETE', route, data);
    },
    put(route, data = {}) {
        return request('PUT', route, data);
    },
    patch(route, data = {}) {
        return request('PATCH', route, data);
    }
}
