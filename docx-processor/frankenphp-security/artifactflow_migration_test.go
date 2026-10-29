package jose

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/hmac"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"reflect"
	"strings"
	"testing"
	"time"

	"go.step.sm/crypto/x25519"
)

// These regressions use only the Smallstep wrapper API so the same tests can
// exercise the original dependency and the source-migrated dependency.
func TestArtifactFlowRejectsOversizedRSAJWKBeforeKeyOperations(t *testing.T) {
	// This is a 4097-bit integer, not an expensive generated RSA key. The
	// original v3 public-key decoder accepts it without applying a modulus cap.
	oversized := make([]byte, 513)
	oversized[0] = 1
	oversized[len(oversized)-1] = 1
	encode := base64.RawURLEncoding.EncodeToString

	for _, private := range []bool{false, true} {
		name := "public"
		fields := map[string]string{
			"kty": "RSA",
			"n":   encode(oversized),
			"e":   "AQAB",
		}
		if private {
			name = "private"
			fields["d"], fields["p"], fields["q"] = "AQ", "Aw", "BQ"
		}
		t.Run(name, func(t *testing.T) {
			data, err := json.Marshal(fields)
			if err != nil {
				t.Fatal(err)
			}
			var key JSONWebKey
			err = json.Unmarshal(data, &key)
			if err == nil || !strings.Contains(err.Error(), "modulus too large") {
				t.Fatalf("oversized RSA modulus must be rejected before key validation; got %v", err)
			}
		})
	}
}

func TestArtifactFlowRejectsInconsistentRSAComponents(t *testing.T) {
	// A short public modulus with much larger factors must be rejected before
	// expensive private-key arithmetic. This fixture is deliberately small
	// enough that running the original decoder is safe.
	factor := make([]byte, 513)
	factor[0], factor[len(factor)-1] = 1, 1
	data, err := json.Marshal(map[string]string{
		"kty": "RSA", "n": "AQAB", "e": "AQAB", "d": "AQ",
		"p": base64.RawURLEncoding.EncodeToString(factor),
		"q": base64.RawURLEncoding.EncodeToString(factor),
	})
	if err != nil {
		t.Fatal(err)
	}
	var key JSONWebKey
	if err := json.Unmarshal(data, &key); err == nil {
		t.Fatal("RSA factors inconsistent with the modulus were accepted")
	}
}

func TestArtifactFlowSupportedSignedRoundTrips(t *testing.T) {
	rsaKey, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	ecKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	hmacKey := bytes.Repeat([]byte{0x42}, 32)
	tests := []struct {
		name string
		alg  SignatureAlgorithm
		key  any
		pub  any
	}{
		{"RSA", RS256, rsaKey, &rsaKey.PublicKey},
		{"EC", ES256, ecKey, &ecKey.PublicKey},
		{"HMAC", HS256, hmacKey, hmacKey},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			signer, err := NewSigner(SigningKey{Algorithm: tc.alg, Key: tc.key}, nil)
			if err != nil {
				t.Fatal(err)
			}
			payload := []byte(`{ "sub" : "alice", "aud" : ["app", "api"] }`)
			signed, err := signer.Sign(payload)
			if err != nil {
				t.Fatal(err)
			}
			compact, err := signed.CompactSerialize()
			if err != nil {
				t.Fatal(err)
			}
			for _, serialized := range []string{compact, signed.FullSerialize()} {
				parsed, err := ParseJWS(serialized)
				if err != nil {
					t.Fatal(err)
				}
				got, err := parsed.Verify(tc.pub)
				if err != nil || !bytes.Equal(got, payload) {
					t.Fatalf("signed payload changed or verification failed: %q, %v", got, err)
				}
				token, err := ParseSigned(serialized)
				if err != nil {
					t.Fatal(err)
				}
				var claims Claims
				if err := Verify(token, tc.pub, &claims); err != nil {
					t.Fatal(err)
				}
				if claims.Subject != "alice" || !reflect.DeepEqual(claims.Audience, Audience{"app", "api"}) {
					t.Fatalf("signed claims changed: %#v", claims)
				}
			}
		})
	}
}

func TestArtifactFlowJSONJWSPreservesSignedBytes(t *testing.T) {
	key := bytes.Repeat([]byte{0x23}, 32)
	// Noncanonical whitespace inside the protected header is covered by the
	// signature. Canonicalizing it to reconstruct compact JWS breaks validity.
	header := []byte(`{ "typ" : "JWT", "alg" : "HS256" }`)
	payload := []byte("{\n  \"sub\" : \"alice\", \"aud\" : [\"app\", \"api\"]\n}")
	encode := base64.RawURLEncoding.EncodeToString
	protected, body := encode(header), encode(payload)
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(protected + "." + body))
	serialized := " \n{ \"payload\":\"" + body + "\", \"protected\":\"" + protected + "\", \"signature\":\"" + encode(mac.Sum(nil)) + "\" }\n "

	parsed, err := ParseJWS(serialized)
	if err != nil {
		t.Fatal(err)
	}
	got, err := parsed.Verify(key)
	if err != nil || !bytes.Equal(got, payload) {
		t.Fatalf("JSON JWS signed bytes changed or verification failed: %q, %v", got, err)
	}
	token, err := ParseSigned(serialized)
	if err != nil {
		t.Fatal(err)
	}
	var claims Claims
	if err := Verify(token, key, &claims); err != nil || claims.Subject != "alice" {
		t.Fatalf("JSON JWT verification failed: %#v, %v", claims, err)
	}

	// A token whose payload no longer matches its signature must still fail.
	tampered := strings.Replace(serialized, body, encode([]byte(`{"sub":"mallory"}`)), 1)
	token, err = ParseSigned(tampered)
	if err != nil {
		t.Fatal(err)
	}
	if err := Verify(token, key, &claims); err == nil {
		t.Fatal("tampered JSON JWT signature was accepted")
	}
}

func TestArtifactFlowSupportedEncryptedRoundTrips(t *testing.T) {
	key := bytes.Repeat([]byte{0x51}, 32)
	for _, contentAlg := range []ContentEncryption{A256GCM, A128CBC_HS256} {
		t.Run(string(contentAlg), func(t *testing.T) {
			encrypter, err := NewEncrypter(contentAlg, Recipient{Algorithm: DIRECT, Key: key}, nil)
			if err != nil {
				t.Fatal(err)
			}
			payload := []byte(`{ "private" : "jwk payload" }`)
			encrypted, err := encrypter.Encrypt(payload)
			if err != nil {
				t.Fatal(err)
			}
			compact, err := encrypted.CompactSerialize()
			if err != nil {
				t.Fatal(err)
			}
			for _, serialized := range []string{compact, encrypted.FullSerialize()} {
				parsed, err := ParseEncrypted(serialized)
				if err != nil {
					t.Fatal(err)
				}
				got, err := parsed.Decrypt(key)
				if err != nil || !bytes.Equal(got, payload) {
					t.Fatalf("encrypted payload changed or decryption failed: %q, %v", got, err)
				}
			}
		})
	}
}

func TestArtifactFlowUnsupportedSignatureAlgorithmFailsClosed(t *testing.T) {
	encode := base64.RawURLEncoding.EncodeToString
	for _, algorithm := range []string{"none", "not-an-algorithm"} {
		t.Run(algorithm, func(t *testing.T) {
			header, err := json.Marshal(map[string]string{"alg": algorithm})
			if err != nil {
				t.Fatal(err)
			}
			// Parsing an unknown algorithm must fail before callers can inspect
			// claims through UnsafeClaimsWithoutVerification.
			token := encode(header) + "." + encode([]byte(`{"sub":"untrusted"}`)) + ".AA"
			if _, err := ParseSigned(token); err == nil {
				t.Fatal("unsupported JWT signature algorithm was accepted")
			}
			if _, err := ParseJWS(token); err == nil {
				t.Fatal("unsupported JWS signature algorithm was accepted")
			}
		})
	}
}

func TestArtifactFlowX25519OpaqueSigner(t *testing.T) {
	publicKey, privateKey, err := x25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	for _, key := range []any{privateKey, X25519Signer(privateKey)} {
		signer, err := NewSigner(SigningKey{Algorithm: XEdDSA, Key: key}, nil)
		if err != nil {
			t.Fatal(err)
		}
		signed, err := signer.Sign([]byte(`{"sub":"nebula"}`))
		if err != nil {
			t.Fatal(err)
		}
		token, err := ParseSigned(signed.FullSerialize())
		if err != nil {
			t.Fatal(err)
		}
		var claims Claims
		if err := Verify(token, publicKey, &claims); err != nil || claims.Subject != "nebula" {
			t.Fatalf("XEdDSA verification failed: %#v, %v", claims, err)
		}
		if _, err := NewSigner(SigningKey{Algorithm: XEdDSA, Key: key}, &SignerOptions{EmbedJWK: true}); err == nil {
			t.Fatal("X25519 JWK embedding must explicitly fail when the underlying JOSE library cannot represent the key")
		}
	}
}

// This helper lets the same behavioral test inspect old and migrated public
// APIs; the required singleton behavior is identical for either field name.
func artifactFlowExpectedAudience(t *testing.T, audiences Audience) Expected {
	t.Helper()
	var expected Expected
	v := reflect.ValueOf(&expected).Elem()
	field := v.FieldByName("Audience")
	if !field.IsValid() {
		field = v.FieldByName("AnyAudience")
	}
	if !field.IsValid() || !field.CanSet() {
		t.Fatal("Expected exposes no configured-audience field")
	}
	field.Set(reflect.ValueOf(audiences))
	return expected
}

func TestArtifactFlowExpectedSingletonAudience(t *testing.T) {
	for _, tc := range []struct {
		name string
		aud  Audience
		ok   bool
	}{
		{"matching", Audience{"app"}, true},
		{"matching with extras", Audience{"other", "app"}, true},
		{"missing", nil, false},
		{"wrong", Audience{"other"}, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			claims := Claims{Audience: tc.aud}
			err := claims.ValidateWithLeeway(artifactFlowExpectedAudience(t, Audience{"app"}), 0)
			if (err == nil) != tc.ok {
				t.Fatalf("audience validation error = %v; want success %v", err, tc.ok)
			}
		})
	}
}

func TestArtifactFlowJWTTimeAndIssuerValidation(t *testing.T) {
	now := time.Unix(1800000000, 0)
	expected := Expected{Issuer: "trusted", Time: now}
	for _, tc := range []struct {
		name   string
		claims Claims
		ok     bool
	}{
		{"valid", Claims{Issuer: "trusted", Expiry: NewNumericDate(now.Add(time.Minute))}, true},
		{"wrong issuer", Claims{Issuer: "other"}, false},
		{"expired", Claims{Issuer: "trusted", Expiry: NewNumericDate(now.Add(-time.Minute))}, false},
		{"not yet valid", Claims{Issuer: "trusted", NotBefore: NewNumericDate(now.Add(time.Minute))}, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			err := tc.claims.ValidateWithLeeway(expected, 0)
			if (err == nil) != tc.ok {
				t.Fatalf("claim validation error = %v; want success %v", err, tc.ok)
			}
		})
	}
}
