import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import './App.css'

type User = { id: number; name: string; role: string }
type Auction = {
  id: number; title: string; description: string; current_price: string
  starting_price: string; reserve_price: string | null; minimum_bid_increment: string
  starts_at: string; ends_at: string; status: string; extensions_count: number
  max_extensions: number; leading_bidder?: { id: number; name: string } | null
  winner?: { id: number; name: string } | null; result?: string
}
type Bid = { id: number; amount: string; type: string; user: string; created_at: string }

const API = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'
const money = (value: string | number) => `Rs. ${Number(value).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`
const localDateTime = (value: string) => {
  const date = new Date(value)
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API}${path}`, { ...options, headers: { 'Content-Type': 'application/json', ...options.headers } })
  const body = await response.json()
  if (!response.ok) throw new Error(body.message || 'Request failed')
  return body
}

function App() {
  const [user, setUser] = useState<User | null>(() => JSON.parse(localStorage.getItem('auction-user') || 'null'))
  const [token, setToken] = useState(() => localStorage.getItem('auction-token') || '')
  const [auctions, setAuctions] = useState<Auction[]>([])
  const [selected, setSelected] = useState<Auction | null>(null)
  const [bids, setBids] = useState<Bid[]>([])
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [bidAmount, setBidAmount] = useState('')
  const [loading, setLoading] = useState(false)
  const [now, setNow] = useState(() => Date.now())
  const detailRequest = useRef(0)
  const resultNotified = useRef<number | null>(null)
  const [selectedAuctionId, setSelectedAuctionId] = useState<number | null>(null)
  const selectedAuctionIdRef = useRef<number | null>(null)

  const loadAuctions = useCallback(async () => {
    try { setAuctions(await request<Auction[]>('/auctions')) } catch (e) { setError((e as Error).message) }
  }, [])
  const loadDetail = useCallback(async (id: number) => {
    if (selectedAuctionIdRef.current !== id) return
    const requestId = ++detailRequest.current
    try {
      const detail = await request<{ auction: Auction; bids: Bid[] }>(`/auctions/${id}`)
      if (requestId !== detailRequest.current || selectedAuctionIdRef.current !== id) return
      setSelected(detail.auction); setBids(detail.bids)
    } catch (e) {
      if (requestId === detailRequest.current) setError((e as Error).message)
    }
  }, [])
  const selectAuction = (id: number) => {
    selectedAuctionIdRef.current = id
    setSelectedAuctionId(id)
    void loadDetail(id)
  }
  useEffect(() => {
    const timer = window.setTimeout(() => void loadAuctions(), 0)
    return () => window.clearTimeout(timer)
  }, [loadAuctions])
  useEffect(() => {
    const timer = window.setInterval(() => void loadAuctions(), 5000)
    return () => window.clearInterval(timer)
  }, [loadAuctions])
  useEffect(() => {
    if (!selectedAuctionId) return
    const timer = window.setInterval(() => { void loadDetail(selectedAuctionId) }, 3000)
    return () => window.clearInterval(timer)
  }, [loadAuctions, loadDetail, selectedAuctionId])
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  useEffect(() => {
    if (!selected || selected.status !== 'completed' || resultNotified.current === selected.id) return
    resultNotified.current = selected.id
    setNotice(selected.result === 'sold'
      ? selected.winner ? `Auction sold. Winner: ${selected.winner.name}.` : 'Auction sold.'
      : 'Auction ended without reaching the reserve price.')
  }, [selected])
  useEffect(() => {
    if (!error && !notice) return
    const timer = window.setTimeout(() => { setError(''); setNotice('') }, 5000)
    return () => window.clearTimeout(timer)
  }, [error, notice])

  const timeLeft = selected ? Math.max(0, new Date(selected.ends_at).getTime() - now) : 0
  const submitAuth = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setError(''); setLoading(true)
    const form = new FormData(event.currentTarget)
    try {
      const body = authMode === 'register'
        ? { name: form.get('name'), email: form.get('email'), password: form.get('password') }
        : { email: form.get('email'), password: form.get('password') }
      const result = await request<{ token: string; user: User }>(`/${authMode}`, { method: 'POST', body: JSON.stringify(body) })
      setToken(result.token); setUser(result.user); localStorage.setItem('auction-token', result.token); localStorage.setItem('auction-user', JSON.stringify(result.user))
    } catch (e) { setError((e as Error).message) } finally { setLoading(false) }
  }
  const placeBid = async (event: FormEvent) => {
    event.preventDefault(); if (!selected || !token) return
    setError(''); setNotice('')
    try {
      await request(`/auctions/${selected.id}/bids`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Idempotency-Key': crypto.randomUUID() },
        body: JSON.stringify({ maximum_amount: Number(bidAmount), idempotency_key: crypto.randomUUID() }),
      })
      setBidAmount(''); setNotice(`Maximum registered. Public price is now refreshed; your Rs. ${Number(bidAmount).toLocaleString('en-IN')} maximum remains private.`); await loadDetail(selected.id)
    } catch (e) { setError((e as Error).message) }
  }
  const createAuction = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setError(''); setNotice('')
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    try {
      const incrementRulesText = form.get('increment_rules')?.toString().trim()
      const incrementRules = incrementRulesText ? JSON.parse(incrementRulesText) : undefined
      await request('/auctions', {
        method: 'POST', headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          title: form.get('title'), description: form.get('description'), starting_price: Number(form.get('starting_price')),
          reserve_price: Number(form.get('reserve_price')), minimum_bid_increment: Number(form.get('minimum_bid_increment')),
          starts_at: form.get('starts_at'), ends_at: form.get('ends_at'), anti_sniping_seconds: 120,
          extension_seconds: 120, max_extensions: 3, increment_rules: incrementRules,
        }),
      })
      formElement.reset(); setNotice('Auction created.'); await loadAuctions()
    } catch (e) { setError((e as Error).message) }
  }
  const updateAuction = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); if (!selected) return
    setError(''); setNotice('')
    const form = new FormData(event.currentTarget)
    try {
      const rulesText = form.get('increment_rules')?.toString().trim()
      await request(`/auctions/${selected.id}`, {
        method: 'PATCH', headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          title: form.get('title'), description: form.get('description'),
          reserve_price: Number(form.get('reserve_price')), minimum_bid_increment: Number(form.get('minimum_bid_increment')),
          starts_at: form.get('starts_at'), ends_at: form.get('ends_at'), status: form.get('status'),
          increment_rules: rulesText ? JSON.parse(rulesText) : undefined,
        }),
      })
      setNotice('Auction updated.'); await loadAuctions(); await loadDetail(selected.id)
    } catch (e) { setError((e as Error).message) }
  }
  const cancelAuction = async () => {
    if (!selected || !window.confirm('Cancel this auction? This cannot be undone.')) return
    setError(''); setNotice('')
    try {
      await request(`/auctions/${selected.id}/cancel`, { method: 'POST', headers: { Authorization: `Bearer ${token}` } })
      setNotice('Auction cancelled.'); await loadAuctions(); await loadDetail(selected.id)
    } catch (e) { setError((e as Error).message) }
  }
  const logout = () => { setToken(''); setUser(null); localStorage.clear() }

  const toast = (message: string, type: 'error' | 'success') => message && <div className={`toast ${type}`} role="status"><span>{type === 'error' ? '!' : '✓'}</span><p>{message}</p><button type="button" aria-label="Dismiss notification" onClick={() => type === 'error' ? setError('') : setNotice('')}>×</button></div>

  if (!user) return <main className="auth-shell">{toast(error, 'error')}{toast(notice, 'success')}<div className="auth-card"><span className="eyebrow">LOT / LIVE</span><h1>Real-time auctions, built for the decisive bid.</h1><p>Join live lots, set your maximum, and let the platform bid intelligently for you.</p><form onSubmit={submitAuth}>
    {authMode === 'register' && <input name="name" placeholder="Full name" required />}
    <input name="email" type="email" placeholder="Email address" required /><input name="password" type="password" placeholder="Password (8+ characters)" required />
    <button disabled={loading}>{loading ? 'Please wait…' : authMode === 'login' ? 'Sign in' : 'Create bidder account'}</button>
  </form><button className="text-button" onClick={() => setAuthMode(authMode === 'login' ? 'register' : 'login')}>{authMode === 'login' ? 'New here? Create an account' : 'Already registered? Sign in'}</button>{error && <p className="error">{error}</p>}</div></main>

  return <main className="app-shell">{toast(error, 'error')}{toast(notice, 'success')}<header><div><span className="eyebrow">LOT / LIVE</span><h1>Good evening, {user.name.split(' ')[0]}.</h1></div><button className="ghost" onClick={logout}>Sign out</button></header>
    <section className="hero-copy"><div><p className="eyebrow">LIVE MARKETPLACE</p><h2>Find your next <em>exceptional</em> lot.</h2></div><p className="muted">Every accepted bid is transactionally verified. Maximum bids stay private while the winning price stays fair.</p></section>
    {user.role === 'admin' && <details className="admin-panel"><summary>Admin · Create auction</summary><form onSubmit={createAuction}><input name="title" placeholder="Auction title" required /><input name="description" placeholder="Description" required /><div className="admin-grid"><input name="starting_price" type="number" placeholder="Starting price" required /><input name="reserve_price" type="number" placeholder="Reserve price" required /><input name="minimum_bid_increment" type="number" placeholder="Fallback increment" required /><input name="starts_at" type="datetime-local" required /><input name="ends_at" type="datetime-local" required /></div><label className="rules-label">Dynamic increments (optional JSON)<textarea name="increment_rules" placeholder={'[{"from":0,"to":10000,"increment":100},{"from":10001,"to":null,"increment":500}'} /></label><button>Create live lot</button></form></details>}
    <div className="workspace"><section className="lots"><div className="section-heading"><h3>Active lots</h3><span>{auctions.length} auctions</span></div>{auctions.map((auction) => <button type="button" className={`lot-card ${selectedAuctionId === auction.id ? 'active' : ''}`} key={auction.id} onClick={() => selectAuction(auction.id)}><div className="lot-top"><span className={`status ${auction.status}`}>{auction.status}</span><span>{new Date(auction.ends_at).toLocaleDateString()}</span></div><h3>{auction.title}</h3><p>{auction.description}</p><strong>{money(auction.current_price)}</strong><small>Current price · {auction.leading_bidder ? 'Leading bid active' : 'Be the first bidder'}</small></button>)}</section>
      <section className="detail">{selected ? <><div className="detail-head"><div><span className={`status ${selected.status}`}>{selected.status}</span><h2>{selected.title}</h2><p>{selected.description}</p></div><div className="countdown"><span>ENDS IN</span><strong>{Math.floor(timeLeft / 3600000).toString().padStart(2, '0')}:{Math.floor(timeLeft / 60000 % 60).toString().padStart(2, '0')}:{Math.floor(timeLeft / 1000 % 60).toString().padStart(2, '0')}</strong></div></div>{user.role === 'admin' && !['completed', 'cancelled'].includes(selected.status) && <details className="manage-panel"><summary>Admin · Manage auction</summary>{['draft', 'scheduled'].includes(selected.status) && <form onSubmit={updateAuction}><input name="title" defaultValue={selected.title} required /><input name="description" defaultValue={selected.description} required /><div className="admin-grid"><input name="reserve_price" type="number" defaultValue={selected.reserve_price || ''} placeholder="Reserve price" /><input name="minimum_bid_increment" type="number" defaultValue={selected.minimum_bid_increment} placeholder="Fallback increment" required /><input name="status" defaultValue={selected.status} /><input name="starts_at" type="datetime-local" defaultValue={localDateTime(selected.starts_at)} required /><input name="ends_at" type="datetime-local" defaultValue={localDateTime(selected.ends_at)} required /></div><textarea name="increment_rules" placeholder="Dynamic increment rules JSON" /><div className="manage-actions"><button>Save changes</button><button type="button" className="danger" onClick={() => void cancelAuction()}>Cancel auction</button></div></form>}{selected.status === 'live' && <button type="button" className="danger" onClick={() => void cancelAuction()}>Cancel live auction</button>}</details>}<div className="price-panel"><span>Current price</span><strong>{money(selected.current_price)}</strong><span>{selected.leading_bidder ? 'Leader is hidden from other bidders' : 'No bids yet'} · increment from {money(selected.minimum_bid_increment)}</span></div><form className="bid-form" onSubmit={placeBid}><label>Your maximum bid<input type="number" min="0.01" step="0.01" value={bidAmount} onChange={(event) => setBidAmount(event.target.value)} placeholder="Enter your maximum" required /></label><button disabled={selected.status !== 'live'}>{selected.status === 'live' ? 'Place maximum bid →' : selected.status === 'scheduled' ? 'Bidding opens soon' : 'Bidding closed'}</button><small>We only bid the minimum required to keep you ahead.</small></form>{selected.status === 'scheduled' && <p className="bid-status">This auction opens on {new Date(selected.starts_at).toLocaleString()}.</p>}{selected.status === 'completed' && <div className="result-panel"><strong>{selected.result === 'sold' ? 'Auction sold' : 'Reserve price not met'}</strong><span>{selected.winner ? `Winner: ${selected.winner.name}` : 'No winner was selected.'}</span></div>}{selected.status === 'cancelled' && <p className="bid-status">This auction was cancelled.</p>}<div className="history"><div className="section-heading"><h3>Live bid history</h3><span>Auto-refreshing</span></div>{bids.map((bid) => <div className="history-row" key={bid.id}><span className="dot" /><div><strong>{bid.user}</strong><small>{new Date(bid.created_at).toLocaleTimeString()}</small></div><b>{money(bid.amount)}</b></div>)}</div></> : <div className="empty"><span>↗</span><h2>Select a lot to enter the live room.</h2><p>Live prices, countdowns, and bid history appear here.</p></div>}</section></div>
  </main>
}

export default App
