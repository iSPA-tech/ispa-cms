// Lazy preview queue: at most MAX_CONCURRENT widget previews render at once, FIFO.
const MAX_CONCURRENT = 2

let running = 0
let waiting = []

function drain() {
    while (running < MAX_CONCURRENT && waiting.length) {
        const { task, resolve, reject } = waiting.shift()
        running++
        Promise.resolve()
            .then(task)
            .then(resolve, reject)
            .finally(() => {
                running--
                drain()
            })
    }
}

export function enqueuePreview(task) {
    return new Promise((resolve, reject) => {
        waiting.push({ task, resolve, reject })
        drain()
    })
}

export function __resetPreviewQueue() {
    running = 0
    waiting = []
}
